/**
 * iHymns — Web PWA Build Script
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Automates the build and packaging of the Web/Browser-based PWA.
 * Copies files from appWeb/public_html_beta/ to a dist/ directory,
 * minifies JS/CSS/HTML, injects version metadata, and prepares
 * the output for SFTP deployment.
 *
 * This script is designed to run on Node.js without external build
 * tools like Vite/Webpack, since the web app is a traditional
 * PHP + vanilla JS application hosted on shared hosting (DreamHost).
 *
 * USAGE:
 *   node tools/build-web.js [--target beta|production|dev]
 *   npm run build:web
 *   npm run build:web -- --target production
 *
 * OUTPUT:
 *   dist/web/ — ready-to-deploy files
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */

/* Derive __dirname for ES modules */
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');

/* Source directory (beta is the primary development target) */
const SOURCE_DIR = path.join(PROJECT_ROOT, 'appWeb', 'public_html_beta');

/* Output directory */
const DIST_DIR = path.join(PROJECT_ROOT, 'dist', 'web');

/* Files and directories to exclude from the build output */
const EXCLUDE_PATTERNS = [
    '.DS_Store',
    '.gitkeep',
    '.gitignore',
    'Thumbs.db',
    '*.map'
];

/* Parse CLI arguments for --target */
const args = process.argv.slice(2);
const targetIndex = args.indexOf('--target');
const target = targetIndex !== -1 && args[targetIndex + 1]
    ? args[targetIndex + 1]
    : 'beta';

/* =========================================================================
 * UTILITY FUNCTIONS
 * ========================================================================= */

/**
 * shouldExclude(filename)
 *
 * Checks if a file should be excluded from the build output
 * based on the EXCLUDE_PATTERNS list.
 *
 * @param {string} filename - The filename to check
 * @returns {boolean} True if the file should be excluded
 */
function shouldExclude(filename) {
    /* Check each exclusion pattern against the filename */
    for (const pattern of EXCLUDE_PATTERNS) {
        /* Handle wildcard patterns (e.g., "*.map") */
        if (pattern.startsWith('*')) {
            const ext = pattern.slice(1);
            if (filename.endsWith(ext)) {
                return true;
            }
        }
        /* Exact match */
        if (filename === pattern) {
            return true;
        }
    }
    return false;
}

/**
 * copyDirectoryRecursive(src, dest)
 *
 * Recursively copies a directory, excluding files matching EXCLUDE_PATTERNS.
 *
 * @param {string} src  - Source directory path
 * @param {string} dest - Destination directory path
 * @returns {number} Count of files copied
 */
function copyDirectoryRecursive(src, dest) {
    /* Create destination directory if it doesn't exist */
    if (!fs.existsSync(dest)) {
        fs.mkdirSync(dest, { recursive: true });
    }

    /* Read all entries in the source directory */
    const entries = fs.readdirSync(src, { withFileTypes: true });
    let fileCount = 0;

    for (const entry of entries) {
        const srcPath = path.join(src, entry.name);
        const destPath = path.join(dest, entry.name);

        /* Skip excluded files */
        if (shouldExclude(entry.name)) {
            continue;
        }

        if (entry.isDirectory()) {
            /* Recurse into subdirectories */
            fileCount += copyDirectoryRecursive(srcPath, destPath);
        } else {
            /* Copy the file */
            fs.copyFileSync(srcPath, destPath);
            fileCount++;
        }
    }

    return fileCount;
}

/**
 * minifyJsFiles(dir)
 *
 * Attempts to minify all .js files in a directory using terser (if installed).
 * Falls back silently if terser is not available.
 *
 * @param {string} dir - Directory to process
 * @returns {number} Count of files minified
 */
function minifyJsFiles(dir) {
    let count = 0;
    const entries = fs.readdirSync(dir, { withFileTypes: true });

    for (const entry of entries) {
        const filePath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            count += minifyJsFiles(filePath);
        } else if (entry.name.endsWith('.js')) {
            try {
                execSync(`npx terser "${filePath}" --compress --mangle --output "${filePath}"`, {
                    stdio: 'pipe',
                    cwd: PROJECT_ROOT
                });
                count++;
            } catch {
                /* terser not available or minification failed — skip silently */
                console.warn(`  ⚠️  Could not minify: ${entry.name}`);
            }
        }
    }

    return count;
}

/**
 * minifyCssFiles(dir)
 *
 * Attempts to minify all .css files using clean-css-cli (if installed).
 *
 * @param {string} dir - Directory to process
 * @returns {number} Count of files minified
 */
function minifyCssFiles(dir) {
    let count = 0;
    const entries = fs.readdirSync(dir, { withFileTypes: true });

    for (const entry of entries) {
        const filePath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            count += minifyCssFiles(filePath);
        } else if (entry.name.endsWith('.css')) {
            try {
                execSync(`npx clean-css-cli -o "${filePath}" "${filePath}"`, {
                    stdio: 'pipe',
                    cwd: PROJECT_ROOT
                });
                count++;
            } catch {
                console.warn(`  ⚠️  Could not minify: ${entry.name}`);
            }
        }
    }

    return count;
}

/**
 * injectBuildMetadata(distDir, targetEnv)
 *
 * Injects build metadata (git commit info, timestamp) into
 * the infoAppVer.php file in the dist output.
 *
 * @param {string} distDir   - The dist directory path
 * @param {string} targetEnv - The deployment target ('beta', 'production', 'dev')
 */
function injectBuildMetadata(distDir, targetEnv) {
    const infoFile = path.join(distDir, 'includes', 'infoAppVer.php');

    /* Only proceed if the file exists in the dist */
    if (!fs.existsSync(infoFile)) {
        console.warn('  ⚠️  infoAppVer.php not found in dist — skipping metadata injection');
        return;
    }

    /* Read the file content */
    let content = fs.readFileSync(infoFile, 'utf-8');

    /* Get git commit info */
    try {
        const commitShaFull = execSync('git rev-parse HEAD', { encoding: 'utf-8', cwd: PROJECT_ROOT }).trim();
        const commitShaShort = commitShaFull.slice(0, 7);
        const commitDate = execSync('git log -1 --format=%cI', { encoding: 'utf-8', cwd: PROJECT_ROOT }).trim();
        const repoUrl = 'https://github.com/MWBMPartners/iHymns';
        const commitUrl = `${repoUrl}/commit/${commitShaFull}`;

        /* Replace NULL placeholders with actual values */
        content = content.replace(
            /\["SHA"\]\["Full"\]\s*=\s*null;/i,
            `["SHA"]["Full"] = "${commitShaFull}";`
        );
        content = content.replace(
            /\["SHA"\]\["Short"\]\s*=\s*null;/i,
            `["SHA"]["Short"] = "${commitShaShort}";`
        );
        content = content.replace(
            /\["Commit"\]\["Date"\]\s*=\s*null;/i,
            `["Commit"]["Date"] = "${commitDate}";`
        );
        content = content.replace(
            /\["Commit"\]\["URL"\]\s*=\s*null;/i,
            `["Commit"]["URL"] = "${commitUrl}";`
        );

        console.log(`  📦 Injected build metadata: ${commitShaShort} (${commitDate})`);
    } catch {
        console.warn('  ⚠️  Could not read git info — skipping metadata injection');
    }

    /* For production builds, clear the development status */
    if (targetEnv === 'production') {
        content = content.replace(
            /\["Development"\]\["Status"\]\s*=>\s*"[^"]*"/g,
            '["Development"]["Status"] => null'
        );
        console.log('  📦 Cleared development status for production build');
    }

    /* Write the modified file back */
    fs.writeFileSync(infoFile, content, 'utf-8');
}

/* =========================================================================
 * MAIN BUILD FUNCTION
 * ========================================================================= */

function main() {
    console.log('');
    console.log('📦 iHymns Web PWA Build');
    console.log('══════════════════════════════════════════════════');
    console.log(`  Source:  ${SOURCE_DIR}`);
    console.log(`  Output:  ${DIST_DIR}`);
    console.log(`  Target:  ${target}`);
    console.log('');

    /* Step 1: Clean the dist directory */
    if (fs.existsSync(DIST_DIR)) {
        fs.rmSync(DIST_DIR, { recursive: true });
        console.log('  🧹 Cleaned previous dist/web/');
    }

    /* Step 2: Copy all files to dist */
    console.log('  📋 Copying files...');
    const fileCount = copyDirectoryRecursive(SOURCE_DIR, DIST_DIR);
    console.log(`  ✅ Copied ${fileCount} files`);

    /* Step 2b: Sync appWeb/data/songs.json from canonical source (data/songs.json) */
    /* On the server, data/ lives one directory up from public_html/ and is shared
       across all environments. The deploy workflow uploads appWeb/data/ separately. */
    const songsSource = path.join(PROJECT_ROOT, 'data', 'songs.json');
    const appWebData = path.join(PROJECT_ROOT, 'appWeb', 'data', 'songs.json');
    if (fs.existsSync(songsSource)) {
        const dataDir = path.dirname(appWebData);
        if (!fs.existsSync(dataDir)) {
            fs.mkdirSync(dataDir, { recursive: true });
        }
        fs.copyFileSync(songsSource, appWebData);
        console.log('  ✅ Synced data/songs.json → appWeb/data/songs.json');
    } else {
        console.warn('  ⚠️  data/songs.json not found — run "npm run parse-songs" first');
    }

    /* Step 3: Inject build metadata */
    console.log('  📦 Injecting build metadata...');
    injectBuildMetadata(DIST_DIR, target);

    /* Step 4: Minify JS (if terser available) */
    console.log('  🔧 Minifying JavaScript...');
    const jsCount = minifyJsFiles(path.join(DIST_DIR, 'js'));
    console.log(`  ✅ Minified ${jsCount} JS files`);

    /* Step 5: Minify CSS (if clean-css available) */
    console.log('  🔧 Minifying CSS...');
    const cssCount = minifyCssFiles(path.join(DIST_DIR, 'css'));
    console.log(`  ✅ Minified ${cssCount} CSS files`);

    /* Step 6: Calculate total dist size */
    let totalSize = 0;
    function calcSize(dir) {
        const entries = fs.readdirSync(dir, { withFileTypes: true });
        for (const entry of entries) {
            const p = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                calcSize(p);
            } else {
                totalSize += fs.statSync(p).size;
            }
        }
    }
    calcSize(DIST_DIR);
    const sizeMB = (totalSize / (1024 * 1024)).toFixed(2);

    /* Summary */
    console.log('');
    console.log('══════════════════════════════════════════════════');
    console.log('✅ Build complete!');
    console.log(`  📦 Output: ${DIST_DIR}`);
    console.log(`  📊 Total size: ${sizeMB} MB`);
    console.log(`  🎯 Target: ${target}`);
    console.log('');
}

/* Run the build */
main();
