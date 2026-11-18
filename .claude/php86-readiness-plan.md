# PHP 8.6 readiness plan (keep 8.4 and 8.5 fully supported)

Written 2026-11-18, the day before PHP 8.6 is released. Source: the development-branch `UPGRADING`
file (php.net was unreachable), so re-check the final release notes before enforcing. Every finding was
checked by reading the code. Scans used PHP's own tokenizer (comments and strings ignored) over all
1,374 tracked `.php` files, vendored PDF library included.

**Owner decisions already made:** (1) a "PHP 8.6" CI check that only advises now, with an easy switch to
make it required later; (2) docs say "PHP 8.4 and 8.5 supported, 8.6 prepared"; (3) where behaviour must
differ, check `PHP_VERSION_ID >= 80600`: the 8.6 way on 8.6, the old way on 8.4/8.5.

**One limit on decision 3:** a version check only helps while code runs. New 8.6 *syntax* (partial
function application, readonly defaults) fails on 8.4 before any `if` is reached, so it cannot be used
while 8.4 is supported. The 8.4 `php -l` run already catches it.

**Branch note:** the checkout was on `claude/cross-site-settings-sync-2143`; follow
`.claude/standing-directives.md` §2 (one working branch) before starting.

---

## A. Code changes needed (verified)

| # | File : line | What changes | Why | Version check? |
|---|---|---|---|---|
| A1 | `appWeb/public_html/includes/musician_duplicates.php:291, 292, 294` | Replace the three `metaphone(...)` calls with a new shared helper `ihymns_metaphone()` in a new file `includes/phonetic.php` | Deprecated in 8.6. Used only to group "might be the same person" names in memory; never stored, so no shared-database risk. | **Yes.** Native `\metaphone()` below 80600; on 8.6 a line-for-line PHP copy of php-src `ext/standard/metaphone.c`. The parity test (D) proves the copy matches native output on the 8.4/8.5 runs. |
| A2 | `tests/php/test-song-save-whole-rollback.php:102` | Make the do-nothing session handler class also implement `\SessionIdInterface` and `\SessionUpdateTimestampHandlerInterface`. Add `create_sid(): string` (returns `bin2hex(random_bytes(16))`), `validateId(string $id): bool` (returns `false`, because this handler never stores anything) and `updateTimestamp(string $id, string $data): bool` (returns `true`). | 8.6 deprecates handlers lacking `create_sid()`/`validateId()`. The interfaces exist since PHP 7. | No (same on all versions). |
| A3 | `appWeb/public_html/includes/network_guard.php:149` | Before `hexdec()`, return `null` when the hex digits, ignoring leading zeros, are more than 8 long (`strlen(ltrim(substr($host, 2), '0')) > 8`) | 8.6 `hexdec()` gives a notice when a number is too big to convert exactly. The `0x…` host comes from an outbound URL with no length limit. The result is already `null` (range check below). | No (same result; just no notice). |
| A4 | `appWeb/public_html/includes/pdf_renderer.php`, just before the vendored autoloader is loaded (around line 128) | `if (!function_exists('mb_regex_encoding'))`, define a small global stand-in that returns `'UTF-8'` when called with no argument and `true` otherwise | mPDF calls `mb_regex_encoding()` on every PDF (`Mpdf.php:1149`, `1423`, `1573`). 8.6 deprecates mbstring's regex part ("mbregex"); builds without it would crash that call, and every server PDF would fall back to browser print. mPDF calls no other mbregex function. | Checks the function exists, not the version — mbregex presence depends on the build, not the version. |

**Checked and fine, so no change:**
- **`return` inside `finally`**: none anywhere, vendor included. The suspected `SongMediaStorage.php:311` is a `return` in the `try` block (fine). The scanner was proven against a planted example.
- **Other deprecations** (`get_charset`, `stmt_init`, `new mysqli_stmt`, other mbregex functions, `spl_object_hash`, `is_double`/`is_long`/`is_integer`, `doubleval`, `strcoll`, `SORT_LOCALE_STRING`, `mb_convert_variables`, three-argument `define()`, objects passed to `http_build_query()` (all 16 calls pass arrays), constructors returning values, reserved names): none in our code.
- **Session defaults** (strict mode, HTTP-only, SameSite=Lax): `manage/includes/auth.php:179-198` already sets `httponly => true` and `samesite => 'Strict'`. Nothing adopts a browser-supplied session id, and login calls `session_regenerate_id(true)`. Strict mode on 8.6 is purely an improvement (see B1).
- **`trim()` now strips form feed**: the stored duplicate key `ihymns_normalize_title()` turns all whitespace (form feed included) into one space (`title_normalize.php:171`) before its last `trim()`, so keys are identical on every version, even with mixed versions on the shared database.
- **Path functions throwing on a zero byte**: request entry points check input shape first (`audio-media.php` allows only `^[A-Za-z0-9]+-\d+$`; `song-media.php`/`org-logo.php` take paths from the database; `qr.php` reads no files; the backup download uses `realpath()`, which has thrown on zero bytes since 8.0). Checked at the entry points, not all 155 call sites.
- **`unpack()` `<`/`>`, `preg_grep()` returning `false`, Unicode 18**: no format of ours contains `<`/`>`; the 3 `preg_grep` uses are ASCII test output; Unicode keeps existing case mappings stable.
- **No global handler turns notices into exceptions** (the one at `service-worker.js.php:31` is narrow), so a new notice cannot crash a page.

## B. Adopt only if genuinely useful

- **B1. Session strict mode on 8.4/8.5 too.** `ini_set('session.use_strict_mode', '1')` before `session_start()` in `initSession()` would give 8.4/8.5 the same protection 8.6 has by default against a planted session id ("session fixation"). It departs from decision 3 (old behaviour on old versions). **Recommendation:** do it; the risk is tiny. Needs the owner's yes; **blocks nothing**. If no, leave it out: 8.6 already follows decision 3 with no code.
- **B2. New 8.6 features** (partial function application, readonly defaults, the `Time\Duration` class, new `SpoofChecker` skeleton methods, the polling API): **no change** (syntax cannot run on 8.4). `SpoofChecker::getSkeleton()` might one day catch look-alike musician names: "for consideration" once 8.6 is the floor.
- **B3. Silencing mPDF's deprecation notices on the live host:** **no change for now.** Production does not display errors, so the cost is one log line per PDF. Revisit only if the log gets noisy.

## C. CI plan and the switch

**Shape:** add `'8.6'` to the existing `php-compat` matrix in `.github/workflows/test.yml` (line 770). No copied job (modularity rule). `PHP ${{ matrix.php }}` yields a new check **"PHP 8.6"**; "PHP 8.4"/"PHP 8.5" keep their names, so alpha's required checks (confirmed via the API: ruleset 20735375 requires `Lint & Validate`, `PHP 8.4`, `PHP 8.5`) are untouched.

**The switch:** repository Actions variable `PHP86_ENFORCE`. Unset (or not `true`) = advisory; `true` = enforcing. Every step that could fail gets:

```yaml
continue-on-error: ${{ matrix.php == '8.6' && vars.PHP86_ENFORCE != 'true' }}
```

On the steps (setup-php included, `id: setup`), not the job: a job-level `continue-on-error` still shows the check red, which would block merges once required. Later steps use `if: steps.setup.outcome == 'success'`, so a broken pre-release install is skipped. A final `if: always()` step writes a `::warning::` and job-summary line naming every step whose `outcome` was `failure` — advisory must never mean silent.

**What the switch can and cannot do:** *Required* is set only in the ruleset (Settings → Rules → Rulesets → alpha → "Require status checks"); a workflow cannot make itself required. The variable only decides whether the check can go red. So:
- **Recommended (one switch at release time):** after the first run creates the "PHP 8.6" check (GitHub only offers names it has seen), add it to the alpha ruleset **now** — in advisory mode it cannot go red, so this costs nothing. Then `gh variable set PHP86_ENFORCE --body true --repo MWBMPartners/iHymns` alone makes 8.6 truly required; deleting the variable undoes it.
- **Alternative (two switches):** add to the ruleset and set the variable together at release (two actions).
- `[skip ci]` commits behave as for 8.4/8.5; the "version commit can be refused" problem gets no worse.

**Deprecations fail the check (8.6 run only):**
1. On the 8.6 run only, setup-php gets `ini-values`: `error_reporting=-1, display_errors=Off, log_errors=On, error_log=<runner.temp>/php86-errors.log, auto_prepend_file=<workspace>/tools/ci/deprecation-trap.php`. Log to a file, **not** stderr: `tools/run-php-tests.php` reads stdout fully before stderr (lines 166-175), so over ~64 KB of stderr would hang the runner.
2. `tools/ci/deprecation-trap.php` installs an error handler for `E_DEPRECATED | E_USER_DEPRECATED`. It appends a JSON line (file, line, message) to `$IHYMNS_DEPRECATION_LOG` and returns `false`, so behaviour is unchanged. It reaches every `php` process the runner starts. Stated gap: a test's own error handler hides deprecations while active.
3. The 8.6 lint step also runs `php -d display_errors=stderr -d error_reporting=-1 -l` and fails on any `Deprecated:` line — this catches compile-time ones (e.g. `return` in `finally`) the trap cannot see.
4. New step `php tools/ci/deprecation-report.php "$IHYMNS_DEPRECATION_LOG"` with `if: always()`. It de-duplicates and splits into **our code** (`::error::`, exit 1) and **third-party** (`appWeb/private_html/lib/`, `::warning::` + summary only), with the same `continue-on-error`. Expected today: mPDF's `mb_regex_encoding`, via `test-print-pdf-batch.php`, as third-party.
5. Update the box comment above `php-compat` (it calls 8.5 "project target"). 8.4/8.5 stay trap-free for now.

## D. Guard test (rule #34: list taken from the code, proven able to fail)

New file `tests/php/test-php86-deprecations.php`, which `tools/run-php-tests.php` picks up automatically:

- **D1. Files to scan, taken from the tree:** walk `appWeb/`, `tests/` and `tools/` for `*.php` with a directory walker (skip `node_modules`), and tokenize each file with `token_get_all`. No typed list of files.
- **D2. Deprecated functions = a fixed minimum list ∪ what PHP itself reports.** Fixed list from `UPGRADING` §4 (metaphone, the mbregex functions, the mysqli functions, `spl_object_hash`, `spl_classes`, `is_double`, `is_long`, `is_integer`, `doubleval`, `strcoll`, `mb_convert_variables`). Union, at runtime, every internal function where `(new ReflectionFunction($f))->isDeprecated()`, (so a future 8.7 deprecation is caught automatically). Plus methods `->get_charset(`, `->stmt_init(`, `->getCsvControl(`, `->setCsvControl(`. On 8.6+, assert every fixed-list name really is deprecated (stale entry fails); below 8.6, print an explicit SKIP.
- **D3. Checks of code structure:** `return` inside a `finally` block; a class that implements `SessionHandlerInterface` but lacks `create_sid` or `validateId`; the `SORT_LOCALE_STRING` constant; `define()` with three arguments; `new mysqli_stmt` without a query.
- **D4. Allow-list with exact counts, which cleans itself up:** `appWeb/private_html/lib/**` is limited to exactly `mb_regex_encoding`×3 and `strcoll`×1 in `Mpdf.php`, and `spl_object_hash`×1 in `FpdiTrait.php`. (iHymns never uses mPDF indexes or FPDI page import, so the last two never run). `includes/phonetic.php`: `metaphone`×1, and it must contain `PHP_VERSION_ID` and `80600`. A count that drops fails as "stale entry" (the `test-fragment-inline-scripts.php` pattern).
- **D5. A built-in self-test:** pass in-memory sample snippets through the same scanner. Each banned form must be found. A mention in a comment, a string, or a method *declaration* named `metaphone` must not be.
- **Metaphone parity test** (`tests/php/test-metaphone-parity.php`): on PHP below 8.6, compare `ihymns_metaphone_userland()` with native `\metaphone()` over a list of hand-picked tricky words (Knight, Wright, Schmidt, Xavier, Ghost, Phillip, Thomas, Wheeler, Aaron, Gnome, the names in the test fixtures) plus 5,000 strings made with a fixed random seed, including some non-ASCII bytes. On every version, compare the hand-picked words against a frozen table of correct answers made on 8.5 and saved in `tests/php/fixtures/`.
- **Prove each guard can fail (do it by hand, then undo):** (a) add `metaphone('x');` to `musician_duplicates.php` → red; (b) add `return 1;` inside a `finally` → red; (c) remove `create_sid` from the A2 handler → red; (d) change one allow-list count → red ("stale"); (e) change one letter rule in the PHP copy of metaphone → the parity test goes red. Restore each one afterwards.

## E. mPDF 8.3.1 / FPDI 2.6.8 risk

- Their `composer.json` files promise support only up to 8.5, but the vendored `platform_check.php` checks only the lowest version (`>= 80000`), so the code still loads on 8.6.
- Real risks: A4's mbregex calls (notices, or a crash without mbregex); `strcoll`/`spl_object_hash` (notices only, on paths we never run). `pdf_renderer.php` catches every `\Throwable` (lines 134, 375, 884), so a crash falls back to browser print, never an error page.
- **Options:** (1) **Recommended:** A4's stand-in function plus marking these notices as third-party in CI, then re-vendor as soon as mPDF and FPDI publish versions that support 8.6. I could not check from here whether they have (no web access). (2) Patch the vendored copy ourselves. Rejected (GPL code; drifts from upstream). (3) Chromium PDF service: far too big.
- **Re-vendoring caveat:** `tools/build-pdf-vendor.sh`, named in the print plan, **does not exist**. Running `composer` on 8.6 will refuse mPDF's `~8.5.0` limit, so re-vendor on 8.5, or use `--ignore-platform-req=php` until upstream updates. Write the steps down in `DEV_NOTES.md` when doing it.

## F. Implementation steps (each small enough for a Sonnet or Haiku implementer)

Local `php` here is **8.3**; the CI runs on 8.4/8.5/8.6 are the real proof.

0. **File the tracking issues** (standing-tasks §2a): an epic "PHP 8.6 readiness", with child issues for A1–A4, the CI switch, the guard, docs, and mPDF re-vendoring. Check: the issues exist and are linked.
1. **A2: session handler in the test.** Check: `php -l tests/php/test-song-save-whole-rollback.php && php tests/php/test-song-save-whole-rollback.php` (the database half skips itself).
2. **A3: length limit in `network_guard`**, plus a case in `tests/php/test-outbound-ssrf-guard.php`: `'0x' . str_repeat('f', 20)` → `null`, and a temporary error handler captures no notice. Check: `php tests/php/test-outbound-ssrf-guard.php`.
3. **A1, part 1:** `includes/phonetic.php` (doc block in plain English, linking the php-src C file), plus the parity test and the frozen answer table. Check: `php tests/php/test-metaphone-parity.php`.
4. **A1, part 2:** point `musician_duplicates.php` at `ihymns_metaphone()` and update its doc-block line 67. Check: `php tests/php/test-musician-dup-scan.php`.
5. **A4: mbregex stand-in.** Check: `php tests/php/test-print-pdf-batch.php && php tests/php/test-pdf-nonlatin-fonts.php`.
6. **`tools/ci/deprecation-trap.php` and `deprecation-report.php`.** Check: `IHYMNS_DEPRECATION_LOG=/tmp/d.jsonl php -d auto_prepend_file=tools/ci/deprecation-trap.php -r 'trigger_error("t", E_USER_DEPRECATED);'; php tools/ci/deprecation-report.php /tmp/d.jsonl; echo "exit=$?"` should exit 1. Run it again on an empty file and it should exit 0.
7. **The guard** `tests/php/test-php86-deprecations.php`. Check: `php tests/php/test-php86-deprecations.php`, then the five hand checks in D.
8. **Workflow:** the matrix entry, the `continue-on-error` expression, `id: setup`, the 8.6-only `ini-values`/lint/report/summary steps, and the box comment. Check: `python3 -c "import yaml,sys;yaml.safe_load(open('.github/workflows/test.yml'))"`, then push. On the PR, "PHP 8.4"/"PHP 8.5" green, "PHP 8.6" appears. Prove the switch: push a temporary `metaphone('x')`; unset variable → 8.6 green with warning summary; set `PHP86_ENFORCE=true`, re-run → red; then delete the variable and revert.
9. **Docs:** `README.md:26,187,242`; `DEV_NOTES.md:1537`; `.claude/ProjectBrief.md:1565`; `wiki/Development-Setup.md`, `Getting-Started.md`, `Home.md`, `Troubleshooting-&-FAQ.md`, and `Deployment-&-CI-CD.md` (which lists the required checks) all become "PHP 8.4 and 8.5 supported; 8.6 prepared (advisory in CI)". Check: `grep -rnE "PHP 8\.1\+|PHP 8\.5\+|8\.1–8\.5" README.md DEV_NOTES.md .claude/ProjectBrief.md wiki/` finds nothing stale.
10. **Rules and notes:** propose `CLAUDE.md` rule #53 (the version-check convention, the syntax limit, the guard) and mirror it in `.OpenAI/` and `AGENTS.md`. Add a `CHANGELOG.md` entry. `WHATS-NEW.md` needs nothing (no user-visible change). Use `fix:`/`ci:` commit prefixes, never `feat:` (rule #46). Check: the standing-tasks checklist.

## G. Risks and what the owner must do

**Risks:** the pre-release `UPGRADING` file may differ from the final release (check before enforcing). setup-php's 8.6 build may arrive without mbregex, which A4 covers. A test with its own error handler hides deprecations from the trap (a known, stated gap). The PHP copy of metaphone could differ subtly from the original, but on 8.4/8.5 the native function is still used, and parity is proven there. Side note (out of scope, file an issue): the `lint` job has no setup-php, so it uses the runner's default PHP (8.3 on ubuntu-latest), below the supported floor.

**Owner actions:**
1. After the first pull-request run, add **"PHP 8.6"** to the alpha ruleset's required checks (recommended now; it is safe while the run only advises).
2. Once 8.6 is released and setup-php ships it as stable, and the 8.6 run has been green for about a week, set `PHP86_ENFORCE=true`.
3. **Decide B1** (session strict mode on 8.4/8.5): yes or no. My recommendation is yes. It does not block anything.
4. **On the host:** move each site to PHP 8.6 in the hosting panel one at a time: alpha first, then beta, then main. Watch the error log a few days before each next move.
5. Re-vendor mPDF/FPDI once upstream publishes support for 8.6.
