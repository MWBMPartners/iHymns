#!/usr/bin/env bash
#
# no-secrets-grep.sh — commit-time secret guard (#1395).
#
# Fails if an obvious secret literal is committed under appApple/. The iHymns
# Apple client holds NO secrets: the bearer token lives in the Keychain; Apple /
# App Store Connect keys are GitHub Actions secrets; the Sign-in-with-Apple
# native client is public by design. So a private-key block or a hard-coded
# high-entropy token assigned to a secret-named field is always a mistake.
#
# Deliberately conservative to avoid false positives on ordinary code (a
# variable *named* apiKey is fine; a private-key PEM block or an assigned long
# opaque literal is not).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"   # -> appApple/
hits=0

# 1. Any PEM private-key block.
if grep -rInE -- "-----BEGIN([ A-Z]+)? PRIVATE KEY-----" \
     --include='*.swift' --include='*.yml' --include='*.yaml' \
     --include='*.plist' --include='*.xcconfig' --include='*.json' \
     "$ROOT" 2>/dev/null; then
    hits=1
fi

# 2. A secret-named field assigned a long opaque literal (>= 20 chars).
if grep -rInE -- \
     "(secret|password|passwd|apikey|api_key|access_token|bearer|p8key)[\"' ]*[:=][\"' ]*[A-Za-z0-9/+_.-]{20,}" \
     --include='*.swift' --include='*.yml' --include='*.yaml' \
     --include='*.plist' --include='*.xcconfig' \
     -i "$ROOT" 2>/dev/null; then
    hits=1
fi

if [ "$hits" -ne 0 ]; then
    echo "NO-SECRETS FAIL: a possible committed secret is shown above. Move it to Keychain / GitHub secrets."
    exit 1
fi
echo "no-secrets OK — no committed secret literals under appApple/."
