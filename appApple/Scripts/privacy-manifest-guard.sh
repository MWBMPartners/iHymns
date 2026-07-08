#!/usr/bin/env bash
#
# privacy-manifest-guard.sh — required-reason API vs PrivacyInfo.xcprivacy
# tripwire (#1451).
#
# ELI5: Apple keeps a short list of "sounds harmless but is actually
# sensitive" system APIs (UserDefaults, file timestamps, free disk space,
# how-long-has-this-device-been-on, which on-screen keyboard is active).
# Any app that calls one of them MUST say why in a PrivacyInfo.xcprivacy
# file, or App Store Connect rejects the upload at submission time — which
# is a much more expensive place to discover a missed declaration than a
# PR review. This script greps the Swift source for those APIs and fails
# the build if it finds one that isn't declared anywhere.
#
# DETAILED: #1449 hand-audited the ENTIRE appApple/ tree once and wrote
# four PrivacyInfo.xcprivacy manifests (Apps/iHymns, Apps/iHymnsTV,
# Apps/iHymnsWatch, Apps/iHymnsWidgets) reflecting what that snapshot
# actually used (only NSPrivacyAccessedAPICategoryUserDefaults, reason
# CA92.1). Nothing stopped a FUTURE change from adding, say, a disk-space
# check for "how much room do I have for offline downloads" without
# anyone remembering to touch the matching manifest — exactly the class of
# drift Xcode's own archive-time privacy-manifest analysis exists to catch,
# but only if someone actually runs an archive build before submission.
# This is the cheap, headless, always-on version of that same check.
#
# DESIGN (deliberately coarse — see #1451's "Rough approach"): this is a
# tripwire, not a fully automated "here's the correct reason code" tool —
# picking the RIGHT reason code per Apple's table needs a human. For each
# category below: if ANY of the grep patterns hit anywhere under
# Packages/iHymnsKit/Sources or Apps/*/Sources, the matching
# NSPrivacyAccessedAPICategory* key must appear in AT LEAST ONE of the
# project's PrivacyInfo.xcprivacy files (not necessarily the "correct"
# per-target one — that finer-grained check would need a real Swift import
# graph, which is exactly the over-engineering #1451 explicitly scoped
# out). A hit with no declaration anywhere fails CI; a human then verifies
# WHICH target's manifest needs the new entry and picks the reason code.
#
# Categories covered are the ones Apple's 2024 policy actually gates and
# #1449's manual audit already checked for:
#   - UserDefaults           (NSPrivacyAccessedAPICategoryUserDefaults)
#   - File timestamp         (NSPrivacyAccessedAPICategoryFileTimestamp)
#   - Disk space             (NSPrivacyAccessedAPICategoryDiskSpace)
#   - System boot time       (NSPrivacyAccessedAPICategorySystemBootTime)
#   - Active keyboard        (NSPrivacyAccessedAPICategoryActiveKeyboards)
# (Symbol lists per Apple's "Describing use of required reason API":
# https://developer.apple.com/documentation/bundleresources/describing-use-of-required-reason-api)
#
# Usage: appApple/Scripts/privacy-manifest-guard.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"   # -> appApple/

# Swift source roots this check scans — mirrors the tree #1449's manual
# audit walked. Uses `find` (not an unquoted glob) so a future app target
# that hasn't been created yet doesn't break the array under `nullglob`-off
# shells the way `Apps/*/Sources` would.
#
# NB: built with a while-read loop rather than bash 4's `mapfile` — macOS
# ships bash 3.2 by default (GPLv2-only, last free version; Apple never
# shipped GPLv3 bash) and both the dev machines and the macos-26 CI runner
# this targets may resolve `#!/usr/bin/env bash` to that same 3.2, which
# has no `mapfile` builtin.
SRC_DIRS=()
[ -d "$ROOT/Packages/iHymnsKit/Sources" ] && SRC_DIRS+=("$ROOT/Packages/iHymnsKit/Sources")
while IFS= read -r dir; do
    SRC_DIRS+=("$dir")
done < <(find "$ROOT/Apps" -mindepth 2 -maxdepth 2 -type d -name 'Sources' 2>/dev/null)

if [ "${#SRC_DIRS[@]}" -eq 0 ]; then
    echo "PRIVACY-MANIFEST FAIL: no Swift source directories found under ${ROOT} — check the script's SRC_DIRS logic." >&2
    exit 1
fi

MANIFESTS=()
while IFS= read -r manifest; do
    MANIFESTS+=("$manifest")
done < <(find "$ROOT" -name 'PrivacyInfo.xcprivacy' 2>/dev/null)

if [ "${#MANIFESTS[@]}" -eq 0 ]; then
    echo "PRIVACY-MANIFEST FAIL: no PrivacyInfo.xcprivacy files found under ${ROOT} — #1449's manifests are missing." >&2
    exit 1
fi

# Parallel arrays: human label | grep -E pattern | manifest category key.
# Patterns for the raw POSIX stat-family C calls avoid \b (a GNU extension
# some grep builds don't support) in favour of a portable
# "not-preceded-by-an-identifier-char" bracket expression, so e.g.
# `myCustomStat(` doesn't false-positive on `stat(`.
NAMES=(
    "UserDefaults"
    "File timestamp"
    "Disk space"
    "System boot time"
    "Active keyboard"
)
PATTERNS=(
    'UserDefaults'
    'creationDate|contentModificationDate|fileModificationDate|getattrlist|attributesOfItem|(^|[^A-Za-z0-9_])(stat|lstat|fstat)\('
    'volumeAvailableCapacity|systemFreeSize|(^|[^A-Za-z0-9_])(statfs|statvfs)\('
    'systemUptime|mach_absolute_time'
    'UITextInputMode|activeInputModes'
)
KEYS=(
    "NSPrivacyAccessedAPICategoryUserDefaults"
    "NSPrivacyAccessedAPICategoryFileTimestamp"
    "NSPrivacyAccessedAPICategoryDiskSpace"
    "NSPrivacyAccessedAPICategorySystemBootTime"
    "NSPrivacyAccessedAPICategoryActiveKeyboards"
)

fail=0

for i in "${!NAMES[@]}"; do
    name="${NAMES[$i]}"
    pattern="${PATTERNS[$i]}"
    key="${KEYS[$i]}"

    # NB: --include MUST precede the `--` option terminator — `--` stops
    # option parsing entirely, so an --include placed after it (as this
    # script originally had it) is silently swallowed as a bogus
    # positional path instead of applied as a filter, and the "hits" would
    # then include non-Swift files (bit us for real: the FIRST version of
    # this script matched its own PrivacyInfo.xcprivacy manifests, whose
    # audit comments quote every API symbol by name).
    hits="$(grep -rlE --include='*.swift' -- "$pattern" "${SRC_DIRS[@]}" 2>/dev/null || true)"
    if [ -z "$hits" ]; then
        continue   # category unused anywhere in the tree — nothing to declare
    fi

    # Match the key ONLY inside its real <string>…</string> XML value, not
    # a bare substring anywhere in the file — every manifest's own doc
    # comment (see the header of each PrivacyInfo.xcprivacy) explains, in
    # prose, which categories were checked and found UNUSED, and quotes
    # the category name while doing so (e.g. "Disk space
    # (NSPrivacyAccessedAPICategoryDiskSpace) — no ... call anywhere").
    # A bare substring grep matches that documentation sentence and wrongly
    # reports the category as declared (caught during this script's own
    # dev-time testing — a synthetic disk-space call was silently
    # swallowed until this was tightened).
    declared=0
    for manifest in "${MANIFESTS[@]}"; do
        if grep -q -- "<string>${key}</string>" "$manifest" 2>/dev/null; then
            declared=1
            break
        fi
    done

    if [ "$declared" -eq 0 ]; then
        echo "PRIVACY-MANIFEST FAIL: '${name}' required-reason API is used but ${key} is not declared in ANY PrivacyInfo.xcprivacy:"
        echo "${hits}" | sed 's/^/    /'
        echo "  -> Add an NSPrivacyAccessedAPITypes entry for ${key} (pick the correct reason code from Apple's table) to the manifest for whichever target's code path reaches this."
        fail=1
    fi
done

if [ "$fail" -eq 0 ]; then
    echo "privacy-manifest-guard OK — every required-reason API family in use has a matching PrivacyInfo.xcprivacy declaration."
fi
exit "$fail"
