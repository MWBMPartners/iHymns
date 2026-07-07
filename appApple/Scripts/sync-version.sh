#!/usr/bin/env bash
#
# sync-version.sh
#
# ELI5: Two jobs every time this runs — (1) double-check the Apple app's
# version number still starts with the SAME first number as the website's
# version (so "iHymns v0.x" always means the same major release on every
# platform), and (2) stamp a fresh "built at this exact minute" build
# number into the Xcode config, so every TestFlight/App Store upload has a
# unique, always-increasing build number without anyone having to remember
# to bump a counter by hand.
#
# DETAILED: Implements strategy §1.8's versioning scheme, called by
# `fastlane/Fastfile`'s `bump_build` lane (kept as a *wrapper* around this
# script rather than re-implementing the parsing/stamping in Ruby, so the
# logic lives in exactly ONE place — this repo's `.claude/CLAUDE.md`
# modularity rule applied to build tooling):
#
#   MARKETING_VERSION     = <sharedMajor>.<appleMinor>.<patch>
#   CURRENT_PROJECT_VERSION = UTC timestamp YYYYMMDDHHmm (monotonic,
#                             collision-free by construction — no separate
#                             build-counter file to keep in sync, see
#                             Config/Versioning.xcconfig's own header)
#
# `<sharedMajor>` is parsed from the WEB app's own version string in
# `appWeb/public_html/includes/infoAppVer.php` (that file's own header:
# "$app['Application']['Version']['Number']" — a plain PHP string literal,
# parsed here with a regex rather than executing PHP, so this script has
# ZERO runtime dependency on a PHP interpreter being present on the Apple
# CI runner). If that major has drifted from what
# `Config/Versioning.xcconfig`'s MARKETING_VERSION currently records, this
# script FAILS LOUD (exit 1) — a silent major-version mismatch between the
# web app and the native app would be confusing support-ticket bait
# ("why does the app say v0.x but the site says v1.x?"), so CI catching it
# immediately is the point.
#
# USAGE:
#   Scripts/sync-version.sh                # assert major parity + stamp build
#   Scripts/sync-version.sh --bump-minor   # ALSO increment Apple's own minor
#                                           # (resets patch to 0), independent
#                                           # of web's own minor — strategy
#                                           # §1.8: "appleMinor auto-increments
#                                           # per Apple release independently
#                                           # of web's minor."
#
# Portable across macOS (BSD tools, the only place this runs today — the
# Apple CI/deploy runner is macOS) and Linux (a contributor's shell, or a
# future non-macOS tooling pass) by using `perl -pi -e` for in-place edits
# instead of `sed -i`, whose "does it take a backup-suffix argument" syntax
# differs between BSD sed (macOS) and GNU sed (Linux) — perl's `-pi` flag
# behaves identically on both. Reference: `perlrun` manual page
# (https://perldoc.perl.org/perlrun), `man 1 sed` (BSD vs GNU behaviour).

set -euo pipefail

# Resolve to `appApple/` regardless of the caller's cwd (same pattern as
# `Scripts/bootstrap.sh`), then to the repo root (one level up), so both the
# web version-info file and the xcconfig can be found via absolute paths.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_APPLE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${APP_APPLE_DIR}/.." && pwd)"

WEB_VERSION_FILE="${REPO_ROOT}/appWeb/public_html/includes/infoAppVer.php"
VERSIONING_XCCONFIG="${APP_APPLE_DIR}/Config/Versioning.xcconfig"

BUMP_MINOR=0
for arg in "$@"; do
    case "${arg}" in
        --bump-minor)
            BUMP_MINOR=1
            ;;
        *)
            echo "error: unknown argument '${arg}' (expected: --bump-minor)" >&2
            exit 2
            ;;
    esac
done

if [[ ! -f "${WEB_VERSION_FILE}" ]]; then
    echo "error: web version file not found at ${WEB_VERSION_FILE}" >&2
    exit 1
fi
if [[ ! -f "${VERSIONING_XCCONFIG}" ]]; then
    echo "error: Versioning.xcconfig not found at ${VERSIONING_XCCONFIG}" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 1. Parse the web app's MAJOR version.
#
# Matches a line like:
#   $app["Application"]["Version"]["Number"] = "0.1321.0";
# and captures the leading integer before the first dot. Deliberately
# tolerant of either "->"-free array-index quoting style PHP allows
# ($app["Version"] vs $app['Version']) since infoAppVer.php could use
# either, and of surrounding whitespace, but requires the specific
# ["Application"]["Version"]["Number"] key path so an unrelated version-
# looking string elsewhere in the file can't be mistaken for it.
# ---------------------------------------------------------------------------
WEB_VERSION_LINE="$(grep -E '\[.Application.\]\[.Version.\]\[.Number.\][[:space:]]*=' "${WEB_VERSION_FILE}" | head -1)"
if [[ -z "${WEB_VERSION_LINE}" ]]; then
    echo "error: could not find \$app[\"Application\"][\"Version\"][\"Number\"] in ${WEB_VERSION_FILE}" >&2
    exit 1
fi

# Extract the quoted version string (e.g. "0.1321.0"), then its leading
# integer (the major).
WEB_VERSION_STRING="$(printf '%s\n' "${WEB_VERSION_LINE}" | grep -oE '"[0-9]+\.[0-9]+\.[0-9]+"' | tr -d '"' | head -1)"
if [[ -z "${WEB_VERSION_STRING}" ]]; then
    echo "error: could not parse a MAJOR.MINOR.PATCH version out of: ${WEB_VERSION_LINE}" >&2
    exit 1
fi
WEB_MAJOR="${WEB_VERSION_STRING%%.*}"

# ---------------------------------------------------------------------------
# 2. Read the Apple app's current MARKETING_VERSION and compare majors.
# ---------------------------------------------------------------------------
XCCONFIG_VERSION_LINE="$(grep -E '^[[:space:]]*MARKETING_VERSION[[:space:]]*=' "${VERSIONING_XCCONFIG}" | head -1)"
if [[ -z "${XCCONFIG_VERSION_LINE}" ]]; then
    echo "error: could not find MARKETING_VERSION in ${VERSIONING_XCCONFIG}" >&2
    exit 1
fi
XCCONFIG_VERSION="$(printf '%s\n' "${XCCONFIG_VERSION_LINE}" | sed -E 's/^[[:space:]]*MARKETING_VERSION[[:space:]]*=[[:space:]]*//' | tr -d '[:space:]')"
XCCONFIG_MAJOR="${XCCONFIG_VERSION%%.*}"

if [[ "${WEB_MAJOR}" != "${XCCONFIG_MAJOR}" ]]; then
    echo "error: MAJOR VERSION DRIFT — web app major is '${WEB_MAJOR}' (from ${WEB_VERSION_STRING})," >&2
    echo "       but appApple/Config/Versioning.xcconfig's MARKETING_VERSION major is '${XCCONFIG_MAJOR}'" >&2
    echo "       (full value: ${XCCONFIG_VERSION}). Update Versioning.xcconfig's MARKETING_VERSION so" >&2
    echo "       its major matches the web app's before continuing (strategy §1.8)." >&2
    exit 1
fi
echo "==> Major version parity OK — web=${WEB_MAJOR}, Apple=${XCCONFIG_MAJOR} (Apple full: ${XCCONFIG_VERSION})"

# ---------------------------------------------------------------------------
# 3. Optionally bump Apple's own MINOR (independent of web's minor), patch
#    reset to 0 — a normal semver "next release" bump.
# ---------------------------------------------------------------------------
if [[ "${BUMP_MINOR}" -eq 1 ]]; then
    IFS='.' read -r _major current_minor _patch <<< "${XCCONFIG_VERSION}"
    NEW_MINOR=$((current_minor + 1))
    NEW_VERSION="${XCCONFIG_MAJOR}.${NEW_MINOR}.0"
    perl -pi -e "s/^(\\s*MARKETING_VERSION\\s*=\\s*).*\$/\${1}${NEW_VERSION}/" "${VERSIONING_XCCONFIG}"
    echo "==> Bumped MARKETING_VERSION: ${XCCONFIG_VERSION} -> ${NEW_VERSION}"
fi

# ---------------------------------------------------------------------------
# 4. Stamp CURRENT_PROJECT_VERSION with a fresh UTC timestamp build number.
#
# YYYYMMDDHHmm is monotonically increasing for any two builds cut more than
# a minute apart and fits comfortably inside the 18-digit ceiling Apple
# places on CFBundleVersion components, with no separate counter file to
# keep in sync across three app shells + a widget extension (they all read
# this ONE xcconfig via `#include`, strategy §1.8).
# ---------------------------------------------------------------------------
NEW_BUILD="$(date -u +%Y%m%d%H%M)"
perl -pi -e "s/^(\\s*CURRENT_PROJECT_VERSION\\s*=\\s*).*\$/\${1}${NEW_BUILD}/" "${VERSIONING_XCCONFIG}"
echo "==> Stamped CURRENT_PROJECT_VERSION = ${NEW_BUILD}"
