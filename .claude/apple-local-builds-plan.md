# Apple app: build and test on the owner's Mac, use GitHub's Mac machines less (plan, 2026-11-24)

Owner's request (2026-11-24): work on the Apple app on real devices, build it locally on the Mac, then push to
GitHub for release, so that GitHub's macOS machines are used as little as possible. This is a plan only. Nothing
here has been run on a Mac; the sandbox has no Apple tools and no Mac is attached.

## 0. Two facts that change the picture

1. **iHymns is a public repository, so its macOS minutes are free today.** GitHub's own timing record for the last
   green Apple CI run (#45312091800) says `billable MACOS total_ms: 0`. The "macOS minutes cost 10 times as much"
   rule applies only to **private** repositories (for example `MWBMPartners/clearlist`, which is private and shares
   the organisation's minute allowance). If iHymns ever became private, every figure below would be billed at 10x.
   So the real gains here are: faster feedback, no waiting in GitHub's queue for a Mac, not holding one of the
   organisation's few at-once Mac slots (shared with ClearList), and being able to sign, install and upload at all.
2. **The Apple build is not a required check.** The `alpha` ruleset requires exactly `Lint & Validate`, `PHP 8.4`,
   `PHP 8.5` (beta, main and release-candidate: the same three). `build` from `apple.yml` is **not** on the list, so
   changing when it runs cannot leave a required check waiting forever. (Side finding: the "Protect main branch"
   ruleset requires `Frontend (ubuntu-latest)` and `Backend (ubuntu-latest)`, which no workflow produces any more.
   That is already a waiting-forever check on `main`; worth its own issue.)

## 1. Where macOS time goes today

| Workflow | When it runs | Mac time per run | Recent use |
|---|---|---|---|
| `apple.yml` "Apple CI", job `build` (`macos-26`) | every pull request touching `appApple/**` (not docs); every new push to that PR re-runs it; manual | green run about 11.5 min (Swift tests 2.7, macOS build 1.9, iOS and watch package builds 2.0, tvOS download 1.5, tvOS build 2.9); a red run 1 to 7 min | 61 runs since July, about 334 Mac minutes in all (about 3,340 if billed at 10x). PR #2206 alone: 7 runs, about 30 min. Its `push` trigger names a branch that no longer exists |
| `apple-deploy.yml` "Apple Deploy" | push to alpha/beta/main touching `appApple/**`; manual | `guard` job is on Ubuntu (seconds); the Mac job is skipped because `APPLE_DEPLOY_ENABLED` is unset | 9 runs ever, none since 2026-08-16. Pull requests merged by the auto-merge robot do not start it at all (GitHub does not start push workflows for merges made with the built-in token, the same reason `auto-merge-alpha.yml` starts `deploy.yml` by hand) |
| `apple-dmg.yml` "Apple macOS DMG" | manual, or a pushed `v*` tag | would be a full Mac archive + notarise | 0 runs; switched off by `APPLE_DMG_ENABLED`. It also creates a tag and a GitHub Release, which rule #46 forbids |
| `build-android.yml` (for contrast) | manual, or a `v*` tag | Ubuntu only | 10 runs, last in May |

The Apple guards that read the Swift source (`tests/test-apple-*.js`) already run on Ubuntu inside the required
`Lint & Validate` job, so they stay automatic whatever we change.

## 2. Ways to cut the Mac time, and how each one meets the required checks and auto-merge

| Option | What changes | Required checks / auto-merge | Cost |
|---|---|---|---|
| A. Do nothing | | An appApple PR already auto-merges on the web checks, often before the Mac build ends (#1526) | about 12 min a PR push |
| B. **Mac job only when asked** (recommended) | `apple.yml` keeps its triggers, but the Mac job runs only on a manual start, or on a PR carrying the label `apple-ci`. The quick checks that need no Mac (line-count budget, secrets search, privacy-manifest guard) move to a small Ubuntu job that always runs on appApple PRs | Nothing required changes, so nothing can get stuck. Auto-merge behaves as today. The skipped Mac job shows as "skipped", not red | 0 Mac minutes unless someone asks |
| C. Ubuntu "decider" + Mac job + always-reporting gate job | Needed only if the owner later wants the Apple build to be **required**: drop the workflow-level path filter, add an Ubuntu job that looks at which files changed, run the Mac job only if Apple files changed, and add a final Ubuntu job (always runs) that passes when the Mac job passed or was skipped. Only that final job's name goes on the ruleset | Safe for auto-merge because the required name always reports. A path-filtered required check would never report on other PRs and would block them forever (project-rules §12) | same as A for Apple PRs, seconds for the rest |
| D. Mac job only on pushes to a release branch | | Same as B | low, but the build is checked after merge, not before |

**Recommendation: B now, with the owner's local `check.sh all` as the real gate,** and C only if the owner later
wants GitHub to block a merge on the Apple build. Keep the job name `build` unchanged (renaming does no harm today
because it is not required, but keeping it avoids a trap if it is ever made required). Also: make `apple-deploy.yml`
and `apple-dmg.yml` manual-start only (no push, no tag trigger), so a local upload and a GitHub upload can never both
send the same build, and the DMG no longer depends on tags.

## 3. One local command for the Mac: `appApple/Scripts/check.sh <step>`

Modelled on ClearList's `ios/scripts/check.sh`: each step logs to `appApple/build/check-<time>/`, a failed step does
not stop the others, the exit code says whether all passed, and `SIMULATOR_UDID` / `CHECK_OUTPUT_DIR` can be set.

| Step | What it runs |
|---|---|
| `doctor` | Xcode 26 selected (prints `xcodebuild -version`, compares with the version CI uses), `xcodegen`, `swiftlint`, `fastlane` found, iOS/tvOS/watchOS simulator runtimes installed, signed in to Xcode with a team, the release settings file present (step `release` only) |
| `lint` | `loc-budget.sh`, `no-secrets-grep.sh`, `privacy-manifest-guard.sh`, `swiftlint --config appApple/.swiftlint.yml appApple`, and `node tools/run-node-tests.js` filtered to `test-apple-*` |
| `test` | `swift test --no-parallel` in `Packages/iHymnsKit` (the same flag CI needs, #1558). Locally the login keychain is unlocked, so the keychain tests are closer to the real thing |
| `build` | `xcodegen generate`, then unsigned builds: `iHymns` for macOS, **`iHymns` for an iOS Simulator with the embedded Watch app** (CI cannot afford the watch runtime; the Mac can), `iHymnsTV` for tvOS Simulator, plus the iOS and watchOS package builds CI already does |
| `uitest` | the app's simulator tests on an iPhone simulator, if and when UI tests exist |
| `all` | `lint`, `test`, `build`, and prints one summary line to paste into the PR ("Apple local check passed on <commit>, Xcode <version>") |
| `device <name>` | Debug build signed with the owner's development team, installed with `xcrun devicectl device install app` on a connected iPhone, iPad or Apple TV; Debug builds talk to `dev.ihymns.app` (`APIEnvironment.defaultForBuild`) |
| `release alpha\|beta\|appstore` | the local upload path below |

**Local release path.** Reuse the Fastfile so there is one recipe, with one change: a local mode (for example
`IHYMNS_LOCAL_SIGNING=1`) that skips `import_distribution_certificate`. That step makes a throw-away keychain the
**default keychain** and then deletes it: fine on a throw-away GitHub machine, harmful on the owner's own Mac. Locally
the Apple Distribution certificate lives in the owner's login keychain (Xcode > Settings > Accounts > Manage
Certificates), and the App Store Connect API key file lives at `~/.appstoreconnect/private_keys/AuthKey_<KEYID>.p8`
(where Apple's upload and notarisation tools look). The three key values (`ASC_KEY_ID`, `ASC_ISSUER_ID`, path to the
`.p8`) and `APPLE_TEAM_ID` go in `~/.config/ihymns/apple-release.env` (permissions 600, outside the repo), which the
script reads. Not needed locally: `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_PASSWORD` (they exist only
to rebuild the keychain on a GitHub machine). Add `*.p8` to `.gitignore` (only `*.p12` is ignored today). The lanes
already archive iOS (with Watch and Widgets) and tvOS and upload with the API key; `sync-version.sh` checks that the
first number matches the website's version and stamps a time-based build number (`YYYYMMDDHHmm`).

**Recording the build without tags (rule #46).** The script refuses to release from a commit that is not on
`origin/alpha` (or beta/main for those lanes) or from a tree with uncommitted changes, puts the stamped
`Versioning.xcconfig` back afterwards, and appends one line to `appApple/dev-docs/release-log.md`: date, version,
build number, lane, the commit built, Xcode version, who. That file sits in a folder both Apple workflows ignore, so
the record PR (`chore(apple): record TestFlight build 1.0.0 (202611241530)`, a build-only title, so no version bump)
uses no Mac time and can never start an upload. A deliberate Apple version change (`sync-version.sh --bump-minor`)
is its own normal PR. No tag, no GitHub Release.

## 4. Real-device test plan for one sign-in per device (PR 3b, plan section 5)

**Set up once.** Xcode 26 (same as CI), `brew install xcodegen swiftlint fastlane`; Xcode signed in with an Apple ID
on the iHymns team. Two iPhones/iPads on one Apple ID with iCloud Keychain on, plus an Apple TV, each with Developer
Mode on and paired in Xcode (Window > Devices and Simulators). Debug builds use `dev.ihymns.app`, which is the
**alpha** channel. On `dev.ihymns.app/manage/configuration`: tick **alpha** for account sync (`profile_sync_channels`)
and for device sign-in (`device_signin_channels`); check the migration cards show applied. "Old build" = alpha
commit `807c5b40` (just before PR 3b, built from a second checkout with `git worktree add`); "new build" = current
alpha. Keep the web Settings > Devices list open on a computer throughout.

| # | Do | Expect |
|---|---|---|
| 1 | Old build on both devices, sign in on one; then new build on device A only | B stays signed in; the list shows A by name plus an "Older app version" row |
| 2 | Sign out on A | B stays signed in; reopening A does not sign it back in |
| 3 | Install new build on a third device (or delete and reinstall on A) with automatic sign-in on; again with it off | On: signs in by itself, own row. Off: asks to sign in |
| 4 | Restore a device from backup (spare device; optional) | It gets its own new row; the old device's sign-in is not copied |
| 5 | Web "Sign out everywhere" | Every device is signed out on next open; none signs back in by itself |
| 6 | Reset the password on the web, then install fresh on a device | The automatic key no longer works; it asks to sign in |
| 7 | Apple TV: new build | Own sign-in, no automatic key; note whether tvOS syncs keychain items at all |
| 8 | Airplane mode on A, first launch after updating to the new build | Still signed in on the old shared sign-in; swaps to its own on the next online launch |
| 9 | Live Activity running on the iPad, then sign out on the iPhone | The iPad's Live Activity keeps updating |

Also check: theme and text size changed on the web reach the app; signing out with unsent changes (airplane mode,
edit a set list) asks first. Record each result, device, build commit and Xcode version as a comment on epic #2180.

## 5. Steps, who does each, and risk

| # | Step | Who | Risk / note |
|---|---|---|---|
| 1 | File issues: "local Apple builds and releases", "main ruleset requires two checks nothing produces", "apple-dmg uses tags against rule #46" | sandbox | none |
| 2 | Write `check.sh` (`bash -n` and shellcheck only here) and the Fastfile local mode (`ruby -c` only) | sandbox | cannot be run here; first real run is the owner's |
| 3 | `apple.yml` option B, plus manual-only `apple-deploy.yml` / `apple-dmg.yml`; `*.p8` in `.gitignore`; `release-log.md` | sandbox | workflow lint runs on Ubuntu; no required check touched |
| 4 | Run `check.sh doctor` then `check.sh all`; send the log folder or summary | owner | first compile on a local Xcode may differ from CI's |
| 5 | Tick alpha for the two switches; run section 4 | owner | needs two devices on one Apple ID plus an Apple TV |
| 6 | Create the `.p8` file and `apple-release.env`; `check.sh release alpha` | owner | blocked anyway by no app icon (#2100) and signing set-up (#2101) |
| 7 | Record PR for the uploaded build | owner or sandbox | build-only title |

Steps 2 and 3 should wait until the other agent's Swift work on this branch is pushed (one working branch rule).
Cannot be checked from the sandbox: anything that compiles Swift, the scripts themselves, signing, the keychain,
iCloud sync, backups, uploads and every device check. Decision for the owner (does not block): option B
(recommended) or C. Reply "B" or "C".
