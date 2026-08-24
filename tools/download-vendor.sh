#!/usr/bin/env bash
# ============================================================================
# download-vendor.sh — Download CDN libraries for local offline fallback
#
# Copyright (c) 2026 iHymns. All rights reserved.
#
# PURPOSE:
# Downloads pinned versions of CDN-hosted libraries (Bootstrap, Font Awesome,
# jQuery, Animate.css, Tone.js, PDF.js, Swagger UI, QR Code Generator) into
# the vendor/ directory under public_html/. These local copies serve as
# fallbacks when the CDN is unreachable (e.g., offline PWA usage).
#
# USAGE:
#   ./tools/download-vendor.sh          # Run from the repo root
#   bash tools/download-vendor.sh       # Alternative invocation
#
# The script reads library URLs from includes/config.php comments below,
# then creates the directory structure and downloads each file.
#
# INTEGRITY (#1666): every download is verified against the SRI hash already
# pinned for it in includes/config.php's APP_CONFIG['libraries'] registry
# (rule #36) — not just checked for being non-empty. A hash mismatch aborts
# the script immediately rather than silently vendoring a divergent file; a
# file with no registry hash of its own (e.g. a webfont binary) is skipped
# with a notice, never a hard failure. See the VENDOR_SRI block below.
#
# NOTE: Run this script after updating library versions in config.php.
# The CI/CD pipeline should also run this during deployment.
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PUB_DIR="$REPO_ROOT/appWeb/public_html"
VENDOR_DIR="$PUB_DIR/vendor"
CONFIG_PHP="$PUB_DIR/includes/config.php"

echo "=== iHymns: Downloading vendor libraries for offline fallback ==="
echo "    Target: $VENDOR_DIR"
echo ""

# ----------------------------------------------------------------------------
# #1666 — SRI registry lookup (local vendored path -> expected integrity hash)
#
# ELI5: before this existed, the script only checked "did SOMETHING get
# downloaded" (a non-empty file). That means a captive portal, a compromised
# mirror, or a tampered-with CDN response could silently hand us a DIFFERENT
# file under the same name and this script would call it a success. The whole
# point of the registry's `integrity` hashes (rule #36 — every CDN load in
# this app is pinned + SRI-checked) is that the browser refuses a mismatched
# file; the vendored LOCAL fallback those loads degrade to deserves the exact
# same guarantee, or it is a fallback in name only.
#
# WHY READ config.php INSTEAD OF RE-TYPING THE HASHES HERE: rule #36's whole
# point is ONE source of truth for a library's version + hash — a second,
# hand-copied hash list in this script is exactly the "two lists that must
# agree with nothing enforcing it" failure class rule #35 keeps finding in
# this codebase (event names, rate-limit pairs, entitlement maps). So this
# script asks PHP itself to read `APP_CONFIG['libraries']` (config.php is a
# plain `define()`, safe to load standalone — its "direct access" guard only
# fires when a WEB REQUEST's SCRIPT_FILENAME matches this file, which is never
# true for a CLI `-r` snippet) and prints every `{variant}_local` path paired
# with its `{variant}_sri` hash (variant = css/js/worker/preset — whichever
# ones a given library entry has). A library file with NO `_sri` sibling for
# its `_local` path (e.g. the bootstrap-icons/Font-Awesome webfont binaries,
# which the registry only hashes at the CSS level) simply never appears in
# this map, which is exactly the "skip-with-notice" case below wants.
# https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity
declare -A VENDOR_SRI
while IFS=$'\t' read -r _rel _hash; do
    [ -n "$_rel" ] && VENDOR_SRI["$_rel"]="$_hash"
done < <(IHYMNS_CONFIG_PATH="$CONFIG_PHP" php -r '
    require getenv("IHYMNS_CONFIG_PATH");
    foreach (APP_CONFIG["libraries"] as $lib) {
        foreach ($lib as $field => $value) {
            if (substr($field, -4) !== "_sri" || !is_string($value)) { continue; }
            $variant   = substr($field, 0, -4);
            $localPath = $lib[$variant . "_local"] ?? "";
            if ($localPath !== "") {
                echo $localPath . "\t" . $value . "\n";
            }
        }
    }
')

if [ "${#VENDOR_SRI[@]}" -eq 0 ]; then
    echo "FATAL: read zero SRI hashes from $CONFIG_PHP — the registry parse is broken" >&2
    echo "       (rule #34: a check that finds nothing is evidence the DERIVATION broke," >&2
    echo "       not that there is nothing to verify). Refusing to download unverified." >&2
    exit 1
fi

# Create vendor directory structure
mkdir -p "$VENDOR_DIR/bootstrap"
mkdir -p "$VENDOR_DIR/fontawesome/css"
mkdir -p "$VENDOR_DIR/fontawesome/webfonts"
mkdir -p "$VENDOR_DIR/jquery"
mkdir -p "$VENDOR_DIR/animate"
mkdir -p "$VENDOR_DIR/fuse"
mkdir -p "$VENDOR_DIR/tone"
mkdir -p "$VENDOR_DIR/pdfjs"
mkdir -p "$VENDOR_DIR/swagger-ui"
mkdir -p "$VENDOR_DIR/sortablejs"
mkdir -p "$VENDOR_DIR/bootstrap-icons/fonts"

# Helper: download a file, verify it's not empty, then verify its hash
# against the registry (#1666) when one is on file for this path.
download() {
    local url="$1"
    local dest="$2"
    local label="$3"

    printf "  %-45s " "$label"
    if curl -fsSL --retry 3 --retry-delay 2 -o "$dest" "$url"; then
        local size
        size=$(wc -c < "$dest")
        if [ "$size" -eq 0 ]; then
            echo "WARN: empty file"
            rm -f "$dest"
            return
        fi
        local human_size
        human_size=$(numfmt --to=iec-i --suffix=B "$size" 2>/dev/null || echo "${size}B")

        # #1666 — hash verification. `dest` is absolute; the registry keys
        # its map on the path RELATIVE to public_html/ (e.g.
        # "vendor/bootstrap/bootstrap.min.css"), matching config.php's
        # `*_local` values verbatim.
        local rel="${dest#"$PUB_DIR"/}"
        local expected="${VENDOR_SRI[$rel]:-}"
        if [ -z "$expected" ]; then
            # Skip-with-notice, not a failure: some vendored files (font
            # binaries a CSS-level hash doesn't cover) legitimately have no
            # registry entry of their own.
            echo "OK ($human_size) [no SRI in registry for $rel — skipped]"
            return
        fi

        local algo actual
        algo="${expected%%-*}"
        actual="${algo}-$(openssl dgst -"$algo" -binary "$dest" | openssl base64 -A)"
        if [ "$actual" != "$expected" ]; then
            echo "HASH MISMATCH"
            {
                echo ""
                echo "SRI VERIFICATION FAILED for '$label' ($dest)"
                echo "  expected (config.php $rel): $expected"
                echo "  got (downloaded file):      $actual"
                echo ""
                echo "The download does NOT match the integrity hash pinned in"
                echo "includes/config.php APP_CONFIG['libraries']. Refusing to keep a"
                echo "vendored fallback that would silently diverge from what the SRI-"
                echo "checked CDN load expects (rule #36). If the library version"
                echo "genuinely changed, update the registry hash there FIRST, then"
                echo "re-run this script — never delete the integrity check to make a"
                echo "mismatch go away (that is exactly how #1647 happened)."
            } >&2
            rm -f "$dest"
            exit 1
        fi
        echo "OK ($human_size) [SRI verified]"
    else
        echo "FAILED"
        rm -f "$dest"
    fi
}

# ---------------------------------------------------------------------------
# Bootstrap 5.3.6
# ---------------------------------------------------------------------------
echo "[1/10] Bootstrap 5.3.6"
download "https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" \
         "$VENDOR_DIR/bootstrap/bootstrap.min.css" \
         "bootstrap.min.css"
download "https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js" \
         "$VENDOR_DIR/bootstrap/bootstrap.bundle.min.js" \
         "bootstrap.bundle.min.js"

# ---------------------------------------------------------------------------
# Font Awesome 6.7.2
# ---------------------------------------------------------------------------
echo "[2/10] Font Awesome 6.7.2"
download "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" \
         "$VENDOR_DIR/fontawesome/css/all.min.css" \
         "all.min.css"

# Download the webfont files referenced by Font Awesome CSS
FA_WEBFONTS=(
    "fa-solid-900.woff2"
    "fa-regular-400.woff2"
    "fa-brands-400.woff2"
)
for font in "${FA_WEBFONTS[@]}"; do
    download "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/webfonts/$font" \
             "$VENDOR_DIR/fontawesome/webfonts/$font" \
             "webfonts/$font"
done

# Patch Font Awesome CSS to use local webfont paths instead of CDN
if [ -f "$VENDOR_DIR/fontawesome/css/all.min.css" ]; then
    printf "  %-45s " "Patching font paths"
    sed -i 's|../webfonts/|../webfonts/|g' "$VENDOR_DIR/fontawesome/css/all.min.css"
    echo "OK (paths already relative)"
fi

# ---------------------------------------------------------------------------
# jQuery 3.7.1
# ---------------------------------------------------------------------------
echo "[3/10] jQuery 3.7.1"
download "https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" \
         "$VENDOR_DIR/jquery/jquery.min.js" \
         "jquery.min.js"

# ---------------------------------------------------------------------------
# Animate.css 4.1.1
# ---------------------------------------------------------------------------
echo "[4/10] Animate.css 4.1.1"
download "https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" \
         "$VENDOR_DIR/animate/animate.min.css" \
         "animate.min.css"

# ---------------------------------------------------------------------------
# Fuse.js 7.1.0
# ---------------------------------------------------------------------------
echo "[5/10] Fuse.js 7.1.0"
download "https://cdn.jsdelivr.net/npm/fuse.js@7.1.0/dist/fuse.min.mjs" \
         "$VENDOR_DIR/fuse/fuse.min.mjs" \
         "fuse.min.mjs"

# ---------------------------------------------------------------------------
# Tone.js 15.1.22
# ---------------------------------------------------------------------------
echo "[6/10] Tone.js 15.1.22"
download "https://cdn.jsdelivr.net/npm/tone@15.1.22/build/Tone.js" \
         "$VENDOR_DIR/tone/Tone.min.js" \
         "Tone.min.js"

# ---------------------------------------------------------------------------
# PDF.js 4.9.124
# ---------------------------------------------------------------------------
echo "[7/10] PDF.js 4.9.124"
download "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.9.124/build/pdf.min.mjs" \
         "$VENDOR_DIR/pdfjs/pdf.min.mjs" \
         "pdf.min.mjs"
download "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.9.124/build/pdf.worker.min.mjs" \
         "$VENDOR_DIR/pdfjs/pdf.worker.min.mjs" \
         "pdf.worker.min.mjs"

# ---------------------------------------------------------------------------
# Swagger UI 5.32.11 — /manage/api-docs (#1587)
#
# The admin API browser used to load the floating `@5` major tag straight from
# the CDN with no SRI and no fallback: one jsDelivr refresh could change the
# code running inside an authenticated admin session, and a CDN outage left a
# blank pane with only a console message. api-docs.php now pins the exact
# version with an integrity hash and falls back to these local copies.
# ---------------------------------------------------------------------------
echo "[8/10] Swagger UI 5.32.11"
download "https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.11/swagger-ui.css" \
         "$VENDOR_DIR/swagger-ui/swagger-ui.css" \
         "swagger-ui.css"
download "https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.11/swagger-ui-bundle.js" \
         "$VENDOR_DIR/swagger-ui/swagger-ui-bundle.js" \
         "swagger-ui-bundle.js"
download "https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.11/swagger-ui-standalone-preset.js" \
         "$VENDOR_DIR/swagger-ui/swagger-ui-standalone-preset.js" \
         "swagger-ui-standalone-preset.js"

# ---------------------------------------------------------------------------
# QR Code Generator — REMOVED (owner directive 2026-08-05: QR via CueRCode).
# iHymns no longer ships a client-side QR library; QR codes are generated by the
# CueRCode service server-side and served by the same-origin /qr.php endpoint
# (includes/cuercode_client.php). Nothing to vendor.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# SortableJS 1.15.2 (#1647)
#
# The card-layout reorder editor injects this from the CDN at runtime WITH an
# integrity attribute. This local copy is the fallback its onerror path uses.
#
# The fallback is what makes the SRI safe to add at all, and that is the whole
# point of vendoring it. SRI was previously removed from this load because a
# PLACEHOLDER hash was committed, silently blocked the script, and killed the
# reorder feature outright -- so the fix was to drop the integrity attribute
# rather than compute the right hash. With a local fallback in place, a hash
# mismatch now degrades to this file instead of to a dead feature.
# ---------------------------------------------------------------------------
echo "[10/10] SortableJS 1.15.2"
download "https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" \
         "$VENDOR_DIR/sortablejs/Sortable.min.js" \
         "Sortable.min.js"

# ---------------------------------------------------------------------------
# Bootstrap-Icons 1.11.3 (#1676)
#
# Registered in APP_CONFIG['libraries']['bootstrap_icons'] and emitted by
# ihymns_bootstrap_css_links(). Previously it had no registry entry and no
# vendored copy at all — four pages hardcoded the CDN URL directly.
#
# The two woff/woff2 files are NOT optional. bootstrap-icons.min.css references
# them with RELATIVE url(./fonts/...) rules, so a local fallback that ships only
# the stylesheet renders every bi-* glyph as an empty box — a fallback that is
# worse than no fallback, because it looks like the CSS loaded fine.
# ---------------------------------------------------------------------------
download "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" \
         "$VENDOR_DIR/bootstrap-icons/bootstrap-icons.min.css" \
         "bootstrap-icons.min.css"
download "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2" \
         "$VENDOR_DIR/bootstrap-icons/fonts/bootstrap-icons.woff2" \
         "bootstrap-icons.woff2"
download "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff" \
         "$VENDOR_DIR/bootstrap-icons/fonts/bootstrap-icons.woff" \
         "bootstrap-icons.woff"

echo ""
echo "=== Done. Vendor libraries downloaded to: $VENDOR_DIR ==="
echo ""
echo "NOTE: Add these to your deployment pipeline. The service worker will"
echo "      use them as a secondary fallback if CDN cache is also unavailable."
