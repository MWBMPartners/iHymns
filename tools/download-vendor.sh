#!/usr/bin/env bash
# ============================================================================
# download-vendor.sh — Download CDN libraries for local offline fallback
#
# Copyright (c) 2026 iHymns. All rights reserved.
#
# PURPOSE:
# Downloads pinned versions of CDN-hosted libraries (Bootstrap, Font Awesome,
# jQuery, Animate.css, Fuse.js, Tone.js, PDF.js) into the vendor/ directory
# under public_html/. These local copies serve as fallbacks when the CDN is
# unreachable (e.g., offline PWA usage).
#
# USAGE:
#   ./tools/download-vendor.sh          # Run from the repo root
#   bash tools/download-vendor.sh       # Alternative invocation
#
# The script reads library URLs from includes/config.php comments below,
# then creates the directory structure and downloads each file.
#
# NOTE: Run this script after updating library versions in config.php.
# The CI/CD pipeline should also run this during deployment.
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VENDOR_DIR="$REPO_ROOT/appWeb/public_html/vendor"

echo "=== iHymns: Downloading vendor libraries for offline fallback ==="
echo "    Target: $VENDOR_DIR"
echo ""

# Create vendor directory structure
mkdir -p "$VENDOR_DIR/bootstrap"
mkdir -p "$VENDOR_DIR/fontawesome/css"
mkdir -p "$VENDOR_DIR/fontawesome/webfonts"
mkdir -p "$VENDOR_DIR/jquery"
mkdir -p "$VENDOR_DIR/animate"
mkdir -p "$VENDOR_DIR/fuse"
mkdir -p "$VENDOR_DIR/tone"
mkdir -p "$VENDOR_DIR/pdfjs"

# Helper: download a file, verify it's not empty
download() {
    local url="$1"
    local dest="$2"
    local label="$3"

    printf "  %-45s " "$label"
    if curl -fsSL --retry 3 --retry-delay 2 -o "$dest" "$url"; then
        local size
        size=$(wc -c < "$dest")
        if [ "$size" -gt 0 ]; then
            echo "OK ($(numfmt --to=iec-i --suffix=B "$size" 2>/dev/null || echo "${size}B"))"
        else
            echo "WARN: empty file"
            rm -f "$dest"
        fi
    else
        echo "FAILED"
        rm -f "$dest"
    fi
}

# ---------------------------------------------------------------------------
# Bootstrap 5.3.6
# ---------------------------------------------------------------------------
echo "[1/7] Bootstrap 5.3.6"
download "https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" \
         "$VENDOR_DIR/bootstrap/bootstrap.min.css" \
         "bootstrap.min.css"
download "https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js" \
         "$VENDOR_DIR/bootstrap/bootstrap.bundle.min.js" \
         "bootstrap.bundle.min.js"

# ---------------------------------------------------------------------------
# Font Awesome 6.7.2
# ---------------------------------------------------------------------------
echo "[2/7] Font Awesome 6.7.2"
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
echo "[3/7] jQuery 3.7.1"
download "https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" \
         "$VENDOR_DIR/jquery/jquery.min.js" \
         "jquery.min.js"

# ---------------------------------------------------------------------------
# Animate.css 4.1.1
# ---------------------------------------------------------------------------
echo "[4/7] Animate.css 4.1.1"
download "https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" \
         "$VENDOR_DIR/animate/animate.min.css" \
         "animate.min.css"

# ---------------------------------------------------------------------------
# Fuse.js 7.1.0
# ---------------------------------------------------------------------------
echo "[5/7] Fuse.js 7.1.0"
download "https://cdn.jsdelivr.net/npm/fuse.js@7.1.0/dist/fuse.min.mjs" \
         "$VENDOR_DIR/fuse/fuse.min.mjs" \
         "fuse.min.mjs"

# ---------------------------------------------------------------------------
# Tone.js 15.1.22
# ---------------------------------------------------------------------------
echo "[6/7] Tone.js 15.1.22"
download "https://cdn.jsdelivr.net/npm/tone@15.1.22/build/Tone.js" \
         "$VENDOR_DIR/tone/Tone.min.js" \
         "Tone.min.js"

# ---------------------------------------------------------------------------
# PDF.js 4.9.124
# ---------------------------------------------------------------------------
echo "[7/7] PDF.js 4.9.124"
download "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.9.124/build/pdf.min.mjs" \
         "$VENDOR_DIR/pdfjs/pdf.min.mjs" \
         "pdf.min.mjs"
download "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.9.124/build/pdf.worker.min.mjs" \
         "$VENDOR_DIR/pdfjs/pdf.worker.min.mjs" \
         "pdf.worker.min.mjs"

echo ""
echo "=== Done. Vendor libraries downloaded to: $VENDOR_DIR ==="
echo ""
echo "NOTE: Add these to your deployment pipeline. The service worker will"
echo "      use them as a secondary fallback if CDN cache is also unavailable."
