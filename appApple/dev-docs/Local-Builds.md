# Building and testing the Apple app on your own Mac

Owner decision (2026-11-24): GitHub builds the Apple app only while the repository is public (that is already how
`apple.yml` is set up). The real work, testing and uploading happens on your own Mac, with one command:
`appApple/Scripts/check.sh`. Plan: `.claude/apple-local-builds-plan.md`.

Nothing here has been run on a Mac yet. The first run is yours; tell us what breaks.

## Set up once

1. Install Xcode 26 and open it once so it finishes installing. Select it: `sudo xcode-select -s /Applications/Xcode_26.app`.
2. `brew install xcodegen swiftlint fastlane` (fastlane is only for uploads) and Node 22 (for the Apple guards).
3. Download the simulator runtimes the build needs: `xcodebuild -downloadPlatform tvOS` and `-downloadPlatform watchOS`.
4. Xcode > Settings > Accounts: sign in with the Apple ID on the iHymns team.
5. Run `appApple/Scripts/check.sh doctor`. It lists what is missing and installs nothing.

## The commands

| Command | What it does |
|---|---|
| `check.sh doctor` | Checks the tools. |
| `check.sh lint` | Size budget, secrets search, privacy-manifest check, SwiftLint, the Apple Node guards. |
| `check.sh test [--no-parallel]` | The iHymnsKit package tests. CI uses `--no-parallel`. |
| `check.sh build` | Unsigned builds: macOS, iOS Simulator (with the Watch app, which CI cannot afford), tvOS Simulator, package builds. |
| `check.sh all` | doctor, lint, test, build, then ONE line to paste into the pull request: `Apple local check passed on <commit>, Xcode <version>`. |
| `check.sh device "<name>"` | Debug build signed with your team, installed on a connected iPhone, iPad or Apple TV. Debug talks to `dev.ihymns.app` (alpha). |
| `check.sh release alpha\|beta\|appstore` | Build, sign and upload from this Mac (TestFlight internal, TestFlight external, App Store). |

Logs for each step are in `appApple/build/check-<time>/`. A failed step does not stop the others.

## Where the secrets live (never in the repository)

- `~/.config/ihymns/apple-release.env`, permissions 600, three lines: `APPLE_TEAM_ID=...`, `ASC_KEY_ID=...`, `ASC_ISSUER_ID=...`.
  The script refuses to read it if anyone else could read it.
- `~/.appstoreconnect/private_keys/AuthKey_<ASC_KEY_ID>.p8`, permissions 600 (the App Store Connect API key).
- The Apple Distribution certificate stays in your login keychain (Xcode > Settings > Accounts > Manage Certificates).
- Not needed on your Mac: `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_PASSWORD`. They exist only so a GitHub machine can rebuild a keychain.

`.gitignore` ignores `*.p8` and `apple-release*.env`, so one cannot be committed by accident.

## What `release` refuses to do, and what it leaves behind

- It refuses unless `HEAD` is already on `origin/<alpha|beta|main>` and the working tree is clean.
- It uses the Fastfile's local mode (`IHYMNS_LOCAL_SIGNING=1`): no throw-away keychain, your own certificate and key file.
- It puts the stamped `Versioning.xcconfig` back afterwards, so a release never leaves a changed file.
- It adds one line to `appApple/dev-docs/release-log.md` (date, version, build number, lane, commit, Xcode, who). Commit that
  line in a small pull request with a build-only title, for example `chore(apple): record TestFlight build 1.0.0 (202611241530)`.
  No git tag and no GitHub Release (rule #46).

## Real-device tests for one sign-in per device (PR 3b)

Set up: two iPhones or iPads on one Apple ID with iCloud Keychain on, plus an Apple TV, each with Developer Mode on and
paired in Xcode. On `dev.ihymns.app/manage/configuration` tick **alpha** for account sync (`profile_sync_channels`) and
device sign-in (`device_signin_channels`), and check the migration cards show applied. "Old build" = alpha commit
`807c5b40` (built from a second checkout with `git worktree add`); "new build" = current alpha. Keep the web
Settings > Devices list open on a computer.

| # | Do | Expect |
|---|---|---|
| 1 | Old build on both devices, sign in on one; then new build on device A only | B stays signed in; the list shows A by name plus an "Older app version" row |
| 2 | Sign out on A | B stays signed in; reopening A does not sign it back in |
| 3 | New build on a third device (or delete and reinstall on A) with automatic sign-in on; again with it off | On: signs in by itself, own row. Off: asks to sign in |
| 4 | Restore a device from backup (spare device; optional) | It gets its own new row; the old device's sign-in is not copied |
| 5 | Web "Sign out everywhere" | Every device is signed out on next open; none signs back in by itself |
| 6 | Reset the password on the web, then install fresh on a device | The automatic key no longer works; it asks to sign in |
| 7 | Apple TV: new build | Own sign-in, no automatic key; note whether tvOS syncs keychain items at all |
| 8 | Airplane mode on A, first launch after updating to the new build | Still signed in on the old shared sign-in; swaps to its own on the next online launch |
| 9 | Live Activity running on the iPad, then sign out on the iPhone | The iPad's Live Activity keeps updating |

Also check: theme and text size changed on the web reach the app; signing out with unsent changes (airplane mode, edit a
set list) asks first. Record each result, device, build commit and Xcode version as a comment on epic #2180.

## What is blocked

- `release` cannot succeed until the app has an icon (#2100) and signing is set up in App Store Connect (#2101).
- `device` needs only your development team, so it can be tried first.
