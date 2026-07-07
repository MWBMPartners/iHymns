#!/usr/bin/env bash
#
# bootstrap.sh
#
# ELI5: Run this after pulling changes (or after editing `project.yml`) to
# (re)build `iHymns.xcodeproj` from scratch. You never hand-edit the
# generated project — this script + `project.yml` are the source of truth.
#
# DETAILED: A one-line wrapper around `xcodegen generate` (strategy §1.1),
# kept as its own script rather than telling every contributor/CI job to
# remember the raw `xcodegen` invocation, and as the one place to grow
# pre/post steps later (e.g. a future `swift package resolve` pin-check, or
# invoking `Scripts/sync-version.sh` — strategy §1.8/§3.4's Phase-0 item
# "Fastlane — match/ASC-key/versioning" — once that script exists).
set -euo pipefail

# Resolve to the `appApple/` directory regardless of the caller's cwd, so
# this script works whether it's run as `./Scripts/bootstrap.sh` from
# `appApple/`, or as `appApple/Scripts/bootstrap.sh` from the repo root.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_APPLE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${APP_APPLE_DIR}"

if ! command -v xcodegen >/dev/null 2>&1; then
    echo "error: xcodegen not found on PATH. Install it with: brew install xcodegen" >&2
    exit 1
fi

echo "==> Generating iHymns.xcodeproj from project.yml ..."
xcodegen generate

echo "==> Done. Open appApple/iHymns.xcodeproj (or run xcodebuild) to build."
