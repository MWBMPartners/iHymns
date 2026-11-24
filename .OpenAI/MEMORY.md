# iHymns — Codex memory

Short, lasting facts. Newest first within each section. Where this file and the newest handoff in
`.claude/sessions/` disagree, the handoff wins. Fix this file when that happens.

_Last updated: 2026-11-24._

## Added 2026-11-24 (layout audit)

- Layout fixes are merged (#2209): shared classes in `admin.css`, guards `tests/test-form-row-alignment.js` and `tests/test-admin-narrow-layout.js` (they read PHP only), measuring scripts in `tools/layout-audit/`. Not measured: high-contrast theme, JavaScript-built markup, the v2 editor.

## Added 2026-11-24 (account rewrite part 3b: one sign-in per device, the Apple app)

- **Part 3b is merged (#2206).** Swift compiled and its tests passed in macOS CI after several fix rounds; no real-device check yet. Steps I1 to I6 of `.claude/account-profile-pr3-plan.md`. Rule #56 in `.claude/CLAUDE.md` has the shape. The sandbox has no Swift toolchain, so CI on macOS is where Swift is compiled. Node guards `tests/test-apple-*.js` read the Swift source (helper `tests/lib/swift-source.js`).
- **Traps:** the device token, install id and notebook MUST be `thisDeviceOnly` (not-synced alone is copied by backups); the shared token `app.ihymns.token` is read-only (`LegacySharedTokenReader`); write "signed out here" BEFORE deleting the token; sign-out of legacy mode makes NO server call; branch on status plus reason word via `DeviceSignInReason` / `ProfileSyncReason`, never on a sentence; every Swift file must stay at or under 400 lines (`appApple/Scripts/loc-budget.sh`), and `RootContainerView.swift` and `Package.swift` sit exactly on it.
- **Decisions:** the app deletes its copy of the key on sign-out (the server cancels all keys when a device's own sign-in ends); song views are sent signed in or anonymous (owner decision 2026-11-24; anonymous ones carry only the install id as `device_id` for the per-device rate limit); sign-out asks before losing unsent changes, enforced in the view model (`signOut(discardUnsent:)`).
- **Found, not fixed (#2207):** an Apple set-list sync clears a web-set expiry (the app's `Setlist` has no `expiresAt`); set-list conflicts, song history and most-viewed are readable but no screen draws them yet.

## Added 2026-11-24 (account rewrite part 3a: one sign-in per device, server side)

- **Part 3a is merged (#2205)**: the server side only; no Apple code yet. Rule #55 in `.claude/CLAUDE.md` has the shape; the plan and its progress table are in `.claude/account-profile-pr3-plan.md`; the reusable design (for SIGNula.id and ClearList) is `.claude/analysis/per-device-sign-in-design.md`.
- **Owner decisions:** the automatic sign-in key is on by default for Apple devices when the switch is on, and can be turned off; a device whose sign-in was signed out or ran out must NOT sign itself back in (enforced on the server with the "ended note" rows, not left to the app); everything sits behind `device_signin_channels` (default none).
- **Trap:** the expiry sweep must keep an install-bound ended row for 180 days (`apiTokenSweepExpired()`; `appWeb/.sql/cleanup.php` mirrors it). A sweep that deletes the row when it expires turns the key back on.
- **No `WHATS-NEW.md` bullets yet**; they are added when production is ticked. The Apple phase (I1 to I6) is not started.

## Added 2026-11-23 (account rewrite part 2c)

- **Part 2c is built on branch `claude/account-pr2c`** (not merged when this was written): download-my-data (`account_export`), delete-my-account on the web, sign-out that clears the browser after asking, and the privacy-page update. Rule #54 in `.claude/CLAUDE.md` has the shape.
- **Owner decisions:** export and web deletion are NOT behind `profile_sync_channels`; sign-out clearing and the new sync still are. At sign-out, ask first if something has not synced.
- **Promote the privacy page together with ticking production** (it is a cached shared page and cannot word itself per site). The user-release-notes bullets for 2a and 2c are added when production is ticked.
- **Test database:** `IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass="` runs the real-database checks (MariaDB 10.11 locally; `test-song-save-whole-rollback.php` still fails there, unrelated).

## Added 2026-11-23

- **Newest handoff:** `.claude/sessions/2026-11-23-HANDOFF.md` (replaces the 18 November one).
- **Version 1.4.1.** A deliberate patch release needs `Release: patch` in the squash-merge **commit message**, not only the pull request description (#2190 versus #2194).
- **Account rewrite part 2a is merged and dormant** (#2197) behind the "New account behaviour" card (`profile_sync_channels`). Part 2b must delete the `tblUserProfileItems` entry in `tests/php/fixtures/orphan-allowlist.php`.
- **The monthly HA audit is paused** (#2190); run it by hand if wanted.
- **Known failure:** `test-song-save-whole-rollback.php` fails on a real MariaDB 10.11 on clean `alpha` too; CI has no database so it skips.
- **Timed lyrics export (#1082):** write our own small PHP formatter; do not adopt `subtitle-toolbox-php`.

## Added 2026-11-18

- **Newest handoff:** `.claude/sessions/2026-11-18-HANDOFF.md`. It lists the owner's decisions O1-O6 (do not re-ask) and the owner to-do list.
- **Merged branches are now deleted automatically** (#2174), and our workflows open pull requests with the GitHub App's token (#2168), so held checks should stop (unproven until the next audit run).
- **Settings sync is off by default** and only ever moves display settings, through one registry (rule #52). Content comes from the account over `/api`.
- **Account rewrite** (epic #2180): part 1 is merged. Parts 2 and 3 follow `.claude/account-profile-rewrite-plan.md`. Review the red-flag steps read-only before each merge.
- **Traps:** do not call PHP with `-r` when `auto_prepend_file` is set; a log on stderr can freeze `tools/run-php-tests.php`; a guard that only passes with a fake reader is wrong-but-green; the Apple app sends no platform at sign-in.

## Working pitfalls

- **Pull requests that our own workflows open could have their checks held (the App token of #2168 should now stop this; unproven)** until a person approves them or
  pushes a real change to the branch (#2127). The monthly audit pull request does this (#2154, #2165).
  No empty commits, and no closing and reopening.
- **There are two wikis.** The repo's `wiki/` folder is the maintained copy. The GitHub wiki
  (`iHymns.wiki`) is a separate repository that cloud sessions cannot push to, and it is months behind.
  Edit `wiki/`, and have the owner publish it.
- **The deploy's "Failed to push version bump" warning should no longer appear** (#2172 fixed it; if it comes back, see #2143). The site deploys either way. Documentation-only pushes run no deploy at all.
- **The Brotli review (#2157) is done and closed**; the importers refuse Brotli. Updating Node, PowerShell and Apple systems is on the owner.

- **Language tags go through `includes/media_language.php` only.** Never write a regular expression for
  a language tag, and never edit `appWeb/public_html/includes/vendor/media-language/` or the other policy
  copies: CI checks them against MeedyaSuite-core. The rules are
  `docs/standards/media-language-bcp47-policy.md` (#2137).
- **Nothing in `appWeb/private_html/` reaches the server.** The deploy skips it because the
  `SFTP_PRIVATE_PATH` secret is not set (#2138). Code the site needs at run time must live in the docroot.
- **An empty review is not a clean review.** When Codex has hit its usage limit it can print nothing
  on its normal output at all. Check the error output, and check the review file actually has
  something in it, before treating the result as "no issues".
- **Codex was at its usage limit from 14 to 20 September 2026.** Reviews in that period were done by
  a stand-in reviewer. A catch-up Codex review of commit `f108b948` is still owed (issue #2123).
- **A "Closes #N" line in a pull request into `alpha` does not close the issue**, because `alpha` is
  not the default branch. Close the issue by hand.
- **Merging a pull request now deletes its branch** (#2174). Older merged branches still need deleting by the owner; check each first.
- **Before switching to `beta` or `main`**, check that branch does not track files that `alpha` has
  stopped tracking. Switching once deleted 1.6 GB of local files (#2108).

## Code facts that surprise people

- **Per-line song data (notes, chords, singing parts) follows a line's identity number, never its
  position** (rule #51 in `.claude/CLAUDE.md`). Carrying it across by position puts it on the wrong
  words, and nobody notices.
- **PHP 8.4 and 8.5 are supported; 8.6 is prepared** (rule #53). Use a `PHP_VERSION_ID >= 80600`
  check where behaviour must differ, never 8.6-only syntax, and `ihymns_metaphone()` instead of
  `metaphone()`. CI has an advisory "PHP 8.6" check; the repository variable `PHP86_ENFORCE=true`
  makes it enforcing.
- **Song reads come straight from MySQL.** There is no whole-catalogue JSON file (rule #17).
- **A page fragment can never run an inline `<script>`.** The site's security policy silently blocks
  it (rule #30).
- **Version numbers:** the visible version sits in `appWeb/public_html/includes/infoAppVer.php`. The
  build number is added separately at deploy time. Pull request titles must start with a short
  label saying what kind of change it is (`feat:` for a new feature, `fix:` for a bug fix, and so on), because the prefix decides whether the version goes up (rule #46).
