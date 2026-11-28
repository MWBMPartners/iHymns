#!/usr/bin/env bash
#
# check.sh - ONE command for building, testing and releasing the Apple app on the owner's own Mac.
#
# ELI5: instead of waiting for GitHub's Mac machines, run `Scripts/check.sh all` on your own Mac. It runs the same
# checks GitHub runs (plus a few GitHub cannot afford), keeps a log of each step, and prints ONE line you can paste
# into a pull request when everything passed. The same script also puts a build on your iPhone or Apple TV
# (`device`) and sends a build to TestFlight or the App Store (`release`).
#
# DETAILED: modelled on ClearList's `ios/scripts/check.sh`. Plan: `.claude/apple-local-builds-plan.md` sections 2-3.
# The commands in `lint`, `test` and `build` are the ones `.github/workflows/apple.yml` runs, so a local pass means
# the same as a CI pass. A step that fails does not stop the others (so one run shows every problem); the exit code
# is non-zero if any step failed. Logs go to `appApple/build/check-<time>/` (or `CHECK_OUTPUT_DIR`).
#
# This script has never been run on a Mac by the person who wrote it (the sandbox has no Xcode). It is checked with
# `bash -n` and by the guard `tests/test-apple-local-check.js`; the first real run is the owner's. It must work with
# the bash that ships with macOS (3.2): no associative arrays, no `mapfile`, no `${var,,}`.
#
# SECRETS (project rules #46 and #56, plan section 3): release settings are read ONLY from
# ~/.config/ihymns/apple-release.env (must be mode 600), and the App Store Connect key file ONLY from
# ~/.appstoreconnect/private_keys/. Nothing here prints, logs or `cat`s either of them, and `set -x` is never used.
# No git tag and no GitHub Release is ever made; a release is recorded as one line in dev-docs/release-log.md.
#
# Usage: see `usage` below, or run `Scripts/check.sh help`.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"          # appApple/
REPO_ROOT="$(cd "${APP_DIR}/.." && pwd)"           # the repository root
KIT_DIR="${APP_DIR}/Packages/iHymnsKit"
XCCONFIG_REL="appApple/Config/Versioning.xcconfig"
RELEASE_LOG="${APP_DIR}/dev-docs/release-log.md"
RELEASE_LOG_REL="appApple/dev-docs/release-log.md"
RELEASE_ENV_FILE="${HOME}/.config/ihymns/apple-release.env"
ASC_KEY_DIR="${HOME}/.appstoreconnect/private_keys"
DERIVED_DATA="${APP_DIR}/build/DerivedData"

usage() {
    cat <<'EOF'
Usage: appApple/Scripts/check.sh <command> [options]

Commands
  doctor                 Check that the tools are installed. Reports what is missing; installs nothing.
  lint                   Size budget, secrets search, privacy-manifest check, SwiftLint, the Apple Node guards.
  test [--no-parallel]   `swift test` for the iHymnsKit package. --no-parallel is what CI uses (#1558).
  build                  Unsigned builds: macOS, iOS Simulator (with the embedded Watch app), tvOS Simulator,
                         and the iOS and watchOS package builds.
  all                    doctor, lint, test (no parallel), build. Prints one summary line to paste into a PR.
  device <name>          Debug build signed with your development team, installed on a connected iPhone, iPad
                         or Apple TV with `xcrun devicectl`. Debug builds talk to dev.ihymns.app (alpha).
  release alpha|beta|appstore
                         Build, sign and upload from this Mac (TestFlight internal / TestFlight external /
                         App Store). Only from a clean tree on a commit that is on origin/<branch>.
  help                   Show this text.

Settings (all optional)
  CHECK_OUTPUT_DIR       Where to keep the logs (default: appApple/build/check-<time>-<pid>).

Release settings (only for `device` and `release`): ~/.config/ihymns/apple-release.env, mode 600, lines of
  APPLE_TEAM_ID=...   ASC_KEY_ID=...   ASC_ISSUER_ID=...
and the key file ~/.appstoreconnect/private_keys/AuthKey_<ASC_KEY_ID>.p8 (mode 600).
Full guide: appApple/dev-docs/Local-Builds.md
EOF
}

die() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

have() { command -v "$1" >/dev/null 2>&1; }

# The number after "Xcode", for example "26.1" (empty if Xcode is not selected).
xcode_version() {
    if have xcodebuild; then
        xcodebuild -version 2>/dev/null | sed -n 's/^Xcode //p' | head -1
    fi
}

short_commit() { git -C "$REPO_ROOT" rev-parse --short HEAD; }

# Octal file mode ("600"). macOS and GNU `stat` spell this differently.
file_mode() {
    if [ "$(uname -s)" = "Darwin" ]; then
        stat -f '%Lp' "$1"
    else
        stat -c '%a' "$1"
    fi
}

# ---------------------------------------------------------------------------
# Step runner: runs one named step, keeps its log, remembers pass or fail, never stops the others.
# Step functions must be a single command (or chain with `|| return 1`), because `set -e` is switched off
# inside anything called from an `if`.
# ---------------------------------------------------------------------------
OUT_DIR=""
FAILED_STEPS=""
PASSED_STEPS=""

ensure_out_dir() {
    if [ -z "$OUT_DIR" ]; then
        OUT_DIR="${CHECK_OUTPUT_DIR:-${APP_DIR}/build/check-$(date +%Y%m%d-%H%M%S)-$$}"
        mkdir -p "$OUT_DIR"
        OUT_DIR="$(cd "$OUT_DIR" && pwd)"
    fi
}

run_step() {
    local name="$1"
    shift
    ensure_out_dir
    printf '\n==> %s\n' "$name"
    if "$@" 2>&1 | tee "${OUT_DIR}/${name}.log"; then
        PASSED_STEPS="${PASSED_STEPS} ${name}"
        printf '    %s passed\n' "$name"
    else
        FAILED_STEPS="${FAILED_STEPS} ${name}"
        printf '    %s FAILED - see %s\n' "$name" "${OUT_DIR}/${name}.log" >&2
    fi
}

# Print the totals; returns non-zero when any step failed.
finish_steps() {
    printf '\n'
    if [ -n "$PASSED_STEPS" ]; then printf 'Passed:%s\n' "$PASSED_STEPS"; fi
    if [ -n "$FAILED_STEPS" ]; then
        printf 'Failed:%s\n' "$FAILED_STEPS" >&2
    fi
    if [ -n "$OUT_DIR" ]; then printf 'Logs: %s\n' "$OUT_DIR"; fi
    [ -z "$FAILED_STEPS" ]
}

# ---------------------------------------------------------------------------
# doctor
# ---------------------------------------------------------------------------
DOCTOR_PROBLEMS=0

doctor_need() {
    # doctor_need <command> <how to get it>
    if have "$1"; then
        printf '  ok       %s\n' "$1"
    else
        printf '  MISSING  %s  (%s)\n' "$1" "$2"
        DOCTOR_PROBLEMS=$((DOCTOR_PROBLEMS + 1))
    fi
}

doctor_runtime() {
    # doctor_runtime <label> <runtime-name-pattern> <list text>
    if printf '%s\n' "$3" | grep -Eq "$2"; then
        printf '  ok       %s simulator runtime\n' "$1"
    else
        printf '  MISSING  %s simulator runtime  (xcodebuild -downloadPlatform %s)\n' "$1" "$1"
        DOCTOR_PROBLEMS=$((DOCTOR_PROBLEMS + 1))
    fi
}

cmd_doctor() {
    DOCTOR_PROBLEMS=0
    printf 'Checking this Mac (nothing is installed or changed)\n'

    doctor_need git "install Xcode or the command line tools"
    doctor_need xcodebuild "install Xcode 26 from the App Store"
    doctor_need xcrun "install Xcode 26 from the App Store"

    local xv major
    xv="$(xcode_version)"
    if [ -n "$xv" ]; then
        major="${xv%%.*}"
        if [ "$major" = "26" ]; then
            printf '  ok       Xcode %s (CI uses Xcode 26)\n' "$xv"
        else
            printf '  MISSING  Xcode 26 selected (found Xcode %s; run: sudo xcode-select -s /Applications/Xcode_26.app)\n' "$xv"
            DOCTOR_PROBLEMS=$((DOCTOR_PROBLEMS + 1))
        fi
    fi

    doctor_need swift "comes with Xcode"
    doctor_need xcodegen "brew install xcodegen"
    doctor_need swiftlint "brew install swiftlint"
    doctor_need fastlane "brew install fastlane (needed for the release command only)"
    doctor_need node "install Node 22 (needed for the Apple guards in lint)"
    doctor_need python3 "comes with the Xcode command line tools (needed for the device command)"

    if have xcrun; then
        local runtimes
        runtimes="$(xcrun simctl list runtimes 2>/dev/null || true)"
        doctor_runtime iOS 'SimRuntime\.iOS-2[6-9]' "$runtimes"
        doctor_runtime tvOS 'SimRuntime\.tvOS-2[6-9]' "$runtimes"
        doctor_runtime watchOS 'SimRuntime\.watchOS-2[6-9]' "$runtimes"
        if xcrun --find devicectl >/dev/null 2>&1; then
            printf '  ok       devicectl (for the device command)\n'
        else
            printf '  MISSING  devicectl (needs Xcode 15 or newer)\n'
            DOCTOR_PROBLEMS=$((DOCTOR_PROBLEMS + 1))
        fi
    fi

    # Release settings are only needed for `device` and `release`, so they are reported but never fail doctor.
    printf 'Release settings (only for the device and release commands):\n'
    if [ -f "$RELEASE_ENV_FILE" ]; then
        if [ "$(file_mode "$RELEASE_ENV_FILE")" = "600" ]; then
            printf '  ok       %s (mode 600)\n' "$RELEASE_ENV_FILE"
        else
            printf '  PROBLEM  %s must be mode 600 (run: chmod 600 on it)\n' "$RELEASE_ENV_FILE"
        fi
    else
        printf '  not set  %s (see appApple/dev-docs/Local-Builds.md)\n' "$RELEASE_ENV_FILE"
    fi
    if [ -d "$ASC_KEY_DIR" ]; then
        printf '  ok       %s exists\n' "$ASC_KEY_DIR"
    else
        printf '  not set  %s (the App Store Connect key goes here)\n' "$ASC_KEY_DIR"
    fi

    if [ "$DOCTOR_PROBLEMS" -eq 0 ]; then
        printf 'Doctor: everything needed is present.\n'
        return 0
    fi
    printf 'Doctor: %s thing(s) missing. Nothing was installed.\n' "$DOCTOR_PROBLEMS" >&2
    return 1
}

# ---------------------------------------------------------------------------
# lint
# ---------------------------------------------------------------------------
step_loc_budget() { bash "${SCRIPT_DIR}/loc-budget.sh"; }
step_no_secrets() { bash "${SCRIPT_DIR}/no-secrets-grep.sh"; }
step_privacy_manifest() { bash "${SCRIPT_DIR}/privacy-manifest-guard.sh"; }
step_swiftlint() { ( cd "$REPO_ROOT" && swiftlint --config appApple/.swiftlint.yml appApple ); }

# Runs every tests/test-apple-*.js guard. The file list comes from the folder, never from a typed list.
step_apple_guards() {
    local f bad=0
    for f in "${REPO_ROOT}"/tests/test-apple-*.js; do
        if ! node "$f"; then
            printf 'guard failed: %s\n' "$f" >&2
            bad=1
        fi
    done
    return "$bad"
}

cmd_lint() {
    run_step loc-budget step_loc_budget
    run_step no-secrets step_no_secrets
    run_step privacy-manifest step_privacy_manifest
    run_step swiftlint step_swiftlint
    run_step apple-guards step_apple_guards
}

# ---------------------------------------------------------------------------
# test
# ---------------------------------------------------------------------------
TEST_PARALLEL_FLAG=""
step_swift_test() {
    if [ -n "$TEST_PARALLEL_FLAG" ]; then
        ( cd "$KIT_DIR" && swift test "$TEST_PARALLEL_FLAG" )
    else
        ( cd "$KIT_DIR" && swift test )
    fi
}

cmd_test() {
    TEST_PARALLEL_FLAG=""
    local arg
    for arg in "$@"; do
        case "$arg" in
            --no-parallel) TEST_PARALLEL_FLAG="--no-parallel" ;;
            *) die "unknown option for test: ${arg} (expected: --no-parallel)" ;;
        esac
    done
    run_step swift-test step_swift_test
}

# ---------------------------------------------------------------------------
# build
# ---------------------------------------------------------------------------
step_xcodegen() { ( cd "$APP_DIR" && xcodegen generate ); }

# Unsigned xcodebuild of one scheme for one destination, the way CI does it.
xcb_unsigned() {
    # xcb_unsigned <scheme> <destination>
    ( cd "$APP_DIR" && xcodebuild \
        -project iHymns.xcodeproj \
        -scheme "$1" \
        -destination "$2" \
        -derivedDataPath "$DERIVED_DATA" \
        CODE_SIGNING_ALLOWED=NO \
        build )
}

step_build_macos() { xcb_unsigned iHymns 'platform=macOS,arch=arm64'; }
# This is the build CI cannot afford: it also builds the embedded Watch app, which needs the watchOS runtime.
step_build_ios_app() { xcb_unsigned iHymns 'generic/platform=iOS Simulator'; }
step_build_tvos() { xcb_unsigned iHymnsTV 'generic/platform=tvOS Simulator'; }

step_kit_ios() {
    ( cd "$KIT_DIR" && swift build \
        --sdk "$(xcrun --sdk iphonesimulator --show-sdk-path)" \
        -Xswiftc -target -Xswiftc arm64-apple-ios26.0-simulator )
}

step_kit_watchos() {
    ( cd "$KIT_DIR" && swift build \
        --sdk "$(xcrun --sdk watchsimulator --show-sdk-path)" \
        -Xswiftc -target -Xswiftc arm64-apple-watchos26.0-simulator )
}

cmd_build() {
    run_step xcodegen step_xcodegen
    # If the project could not be made, the three app builds below cannot work; say so instead of piling on.
    case "$FAILED_STEPS" in
        *xcodegen*) return 0 ;;
    esac
    run_step build-macos step_build_macos
    run_step build-ios-app-with-watch step_build_ios_app
    run_step build-tvos step_build_tvos
    run_step kit-ios step_kit_ios
    run_step kit-watchos step_kit_watchos
}

# ---------------------------------------------------------------------------
# all
# ---------------------------------------------------------------------------
cmd_all() {
    cmd_doctor || die "doctor found missing tools; fix them first (nothing was installed for you)"
    cmd_lint
    cmd_test --no-parallel
    cmd_build
    if finish_steps; then
        local commit xv dirty
        commit="$(short_commit)"
        xv="$(xcode_version)"
        dirty="$(git -C "$REPO_ROOT" status --porcelain)"
        printf '\n'
        if [ -n "$dirty" ]; then
            # A pass on files that are not committed says nothing about the commit, so do not hand out the line.
            printf 'Everything passed, but this tree has uncommitted changes, so no PR line is printed. Commit, then run again.\n'
        else
            printf 'Apple local check passed on %s, Xcode %s\n' "$commit" "$xv"
        fi
        return 0
    fi
    return 1
}

# ---------------------------------------------------------------------------
# Release settings (used by `device` and `release`)
# ---------------------------------------------------------------------------

# Reads ONLY the three known names from the settings file. It never runs the file as a script and never prints a
# value. A file that other people could read is refused, because it names the account and the key.
load_release_env() {
    [ -f "$RELEASE_ENV_FILE" ] || die "release settings file not found: ${RELEASE_ENV_FILE} (see appApple/dev-docs/Local-Builds.md)"
    local mode
    mode="$(file_mode "$RELEASE_ENV_FILE")"
    [ "$mode" = "600" ] || die "${RELEASE_ENV_FILE} must be mode 600 (it is ${mode}); fix it with: chmod 600 on that file"

    local line key val
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            '' | '#'*) continue ;;
        esac
        line="${line#export }"
        case "$line" in
            *=*) ;;
            *) continue ;;
        esac
        key="${line%%=*}"
        val="${line#*=}"
        case "$val" in
            \"*\") val="${val#\"}"; val="${val%\"}" ;;
            \'*\') val="${val#\'}"; val="${val%\'}" ;;
        esac
        case "$key" in
            APPLE_TEAM_ID) APPLE_TEAM_ID="$val" ;;
            ASC_KEY_ID) ASC_KEY_ID="$val" ;;
            ASC_ISSUER_ID) ASC_ISSUER_ID="$val" ;;
        esac
    done < "$RELEASE_ENV_FILE"
}

# Each value must look like what it is (letters, digits, dashes), so it can never smuggle anything into a path.
require_setting() {
    # require_setting <name> <value>
    local name="$1" value="$2"
    [ -n "$value" ] || die "${name} is missing from ${RELEASE_ENV_FILE}"
    case "$value" in
        *[!A-Za-z0-9-]*) die "${name} in ${RELEASE_ENV_FILE} has characters it should not have (only letters, digits and dashes)" ;;
    esac
}

APPLE_TEAM_ID=""
ASC_KEY_ID=""
ASC_ISSUER_ID=""

# ---------------------------------------------------------------------------
# device <name>
# ---------------------------------------------------------------------------

# Looks the device up by name (or identifier) in `devicectl`'s JSON and prints "<platform> <identifier>".
find_device() {
    local json="$1" wanted="$2" result="${json}.picked"
    python3 - "$json" "$wanted" > "$result" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as source:
    devices = json.load(source).get("result", {}).get("devices", [])
wanted = sys.argv[2].lower()
found = []
for device in devices:
    name = device.get("deviceProperties", {}).get("name", "")
    ident = device.get("identifier", "")
    udid = device.get("hardwareProperties", {}).get("udid", "")
    platform = device.get("hardwareProperties", {}).get("platform", "")
    if wanted in (name.lower(), ident.lower(), udid.lower()):
        found.append((platform, ident, name))
if len(found) != 1:
    names = ", ".join(d.get("deviceProperties", {}).get("name", "?") for d in devices) or "none"
    sys.exit("expected exactly one device called that; found %d. Devices seen: %s" % (len(found), names))
print("%s %s" % (found[0][0], found[0][1]))
PY
    cat "$result"
}

cmd_device() {
    local name="${1:-}"
    [ -n "$name" ] || die "device needs the device name, for example: check.sh device \"Jon's iPhone\""
    have xcrun || die "xcrun not found (install Xcode 26)"
    have python3 || die "python3 not found (run: check.sh doctor)"
    have xcodegen || die "xcodegen not found (brew install xcodegen)"
    load_release_env
    require_setting APPLE_TEAM_ID "$APPLE_TEAM_ID"

    ensure_out_dir
    xcrun devicectl list devices --json-output "${OUT_DIR}/devices.json" >/dev/null 2>&1 \
        || die "could not list devices; is the device plugged in, unlocked, with Developer Mode on?"
    local picked platform ident scheme products
    picked="$(find_device "${OUT_DIR}/devices.json" "$name")" || die "no single matching device (see the message above)"
    platform="${picked%% *}"
    ident="${picked#* }"
    case "$platform" in
        iOS) scheme="iHymns"; products="Debug-iphoneos" ;;
        tvOS) scheme="iHymnsTV"; products="Debug-appletvos" ;;
        *) die "this command installs on iPhone, iPad or Apple TV only (this device reports: ${platform})" ;;
    esac

    printf 'Building Debug for %s (it talks to dev.ihymns.app, the alpha channel)\n' "$name"
    ( cd "$APP_DIR" && xcodegen generate ) || die "xcodegen failed"
    local device_dd="${APP_DIR}/build/DerivedData-device"
    ( cd "$APP_DIR" && xcodebuild \
        -project iHymns.xcodeproj \
        -scheme "$scheme" \
        -configuration Debug \
        -destination "id=${ident}" \
        -derivedDataPath "$device_dd" \
        -allowProvisioningUpdates \
        CODE_SIGN_STYLE=Automatic \
        DEVELOPMENT_TEAM="$APPLE_TEAM_ID" \
        build ) 2>&1 | tee "${OUT_DIR}/device-build.log" || die "device build failed - see ${OUT_DIR}/device-build.log"

    local app
    app="$(find "${device_dd}/Build/Products/${products}" -maxdepth 1 -name '*.app' 2>/dev/null | head -1)"
    [ -n "$app" ] || die "built app not found under ${device_dd}/Build/Products/${products}"
    xcrun devicectl device install app --device "$ident" "$app" || die "install failed"
    # Starting the app is a convenience only; a failure here is not an error.
    xcrun devicectl device process launch --device "$ident" app.ihymns >/dev/null 2>&1 || true
    printf 'Installed %s on %s.\n' "$(basename "$app")" "$name"
}

# ---------------------------------------------------------------------------
# release alpha|beta|appstore
# ---------------------------------------------------------------------------
RELEASE_RESTORE_ARMED=0

# Puts the stamped Versioning.xcconfig back to the committed text. Safe to call twice.
restore_xcconfig() {
    if [ "$RELEASE_RESTORE_ARMED" -eq 1 ]; then
        git -C "$REPO_ROOT" checkout -- "$XCCONFIG_REL" 2>/dev/null || true
        RELEASE_RESTORE_ARMED=0
    fi
}

# Refuses unless this is a clean tree on a commit that origin/<branch> already contains.
release_git_guard() {
    local branch="$1"
    git -C "$REPO_ROOT" fetch --quiet origin "+refs/heads/${branch}:refs/remotes/origin/${branch}" \
        || die "could not fetch origin/${branch}; a release must be checked against the real branch"
    git -C "$REPO_ROOT" merge-base --is-ancestor HEAD "origin/${branch}" \
        || die "HEAD is not on origin/${branch}. Releases come only from commits already on that branch (check out or merge it first)."
    local dirty
    # The release log itself is allowed to be modified: it holds the lines from earlier uploads not yet recorded.
    dirty="$(git -C "$REPO_ROOT" status --porcelain -- . ":(exclude)${RELEASE_LOG_REL}")"
    [ -z "$dirty" ] || die "the working tree has uncommitted changes. Commit or stash them first; a release must be exactly a commit."
}

cmd_release() {
    local target="${1:-}" branch lane
    case "$target" in
        alpha) branch="alpha"; lane="alpha" ;;
        beta) branch="beta"; lane="beta" ;;
        appstore) branch="main"; lane="release" ;;
        *) die "release needs one of: alpha, beta, appstore" ;;
    esac
    have fastlane || die "fastlane not found (brew install fastlane)"
    have xcodegen || die "xcodegen not found (brew install xcodegen)"
    have xcodebuild || die "xcodebuild not found (install Xcode 26)"

    release_git_guard "$branch"

    load_release_env
    require_setting APPLE_TEAM_ID "$APPLE_TEAM_ID"
    require_setting ASC_KEY_ID "$ASC_KEY_ID"
    require_setting ASC_ISSUER_ID "$ASC_ISSUER_ID"
    local key_file="${ASC_KEY_DIR}/AuthKey_${ASC_KEY_ID}.p8"
    [ -f "$key_file" ] || die "App Store Connect key file not found: ${key_file}"
    local key_mode
    key_mode="$(file_mode "$key_file")"
    [ "$key_mode" = "600" ] || die "${key_file} must be mode 600 (it is ${key_mode})"

    local commit xv
    commit="$(git -C "$REPO_ROOT" rev-parse HEAD)"
    xv="$(xcode_version)"
    printf 'Releasing %s from commit %s with Xcode %s\n' "$target" "${commit:0:10}" "$xv"
    if [ "$target" = "appstore" ]; then
        printf 'This uploads to the App Store. Type appstore to continue: '
        local answer=""
        read -r answer || true
        [ "$answer" = "appstore" ] || die "not confirmed; nothing was uploaded"
    fi

    # From here the stamped build number is written into the committed Versioning.xcconfig, so always put it back.
    RELEASE_RESTORE_ARMED=1
    trap restore_xcconfig EXIT

    # The three values go to fastlane through its environment only; nothing is printed.
    export IHYMNS_LOCAL_SIGNING=1
    export ASC_KEY_ID ASC_ISSUER_ID APPLE_TEAM_ID
    export ASC_KEY_PATH="$key_file"
    ensure_out_dir
    ( cd "$APP_DIR" && fastlane "$lane" ) 2>&1 | tee "${OUT_DIR}/release-${target}.log" \
        || die "fastlane ${lane} failed - see ${OUT_DIR}/release-${target}.log (the version file was put back)"

    # Read what was stamped, put the file back, then record the build.
    local version build
    version="$(sed -n 's/^[[:space:]]*MARKETING_VERSION[[:space:]]*=[[:space:]]*//p' "${REPO_ROOT}/${XCCONFIG_REL}" | head -1)"
    build="$(sed -n 's/^[[:space:]]*CURRENT_PROJECT_VERSION[[:space:]]*=[[:space:]]*//p' "${REPO_ROOT}/${XCCONFIG_REL}" | head -1)"
    restore_xcconfig

    local who stamp
    who="$(git -C "$REPO_ROOT" config user.name 2>/dev/null || true)"
    [ -n "$who" ] || who="${USER:-unknown}"
    stamp="$(date -u +%Y-%m-%dT%H:%MZ)"
    printf '%s | %s | %s | %s | %s | Xcode %s | %s\n' \
        "$stamp" "$version" "$build" "$target" "${commit:0:10}" "$xv" "$who" >> "$RELEASE_LOG"
    printf '\nUploaded %s (%s) to %s. One line was added to %s.\n' "$version" "$build" "$target" "$RELEASE_LOG_REL"
    printf 'Next: commit that line in a small pull request titled like: chore(apple): record TestFlight build %s (%s)\n' "$version" "$build"
}

# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
main() {
    local command="${1:-help}"
    if [ "$#" -gt 0 ]; then shift; fi
    case "$command" in
        doctor) cmd_doctor ;;
        lint) cmd_lint; finish_steps ;;
        test) cmd_test "$@"; finish_steps ;;
        build) cmd_build; finish_steps ;;
        all) cmd_all ;;
        device) cmd_device "$@" ;;
        release) cmd_release "$@" ;;
        help | -h | --help) usage ;;
        *) usage >&2; die "unknown command: ${command}" ;;
    esac
}

main "$@"
