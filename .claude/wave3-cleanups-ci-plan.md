# Wave 3 — Small Cleanups + CI — Implementation Plan

**Program:** 2026-08-21 queued-gap program, Wave 3 (sequential Fable-5 planning pass → Sonnet implementation).
**Branch:** `claude/ilyrics-identity-work-model` (the one working branch — standing-directives: no PR stacking).
**Planned:** 2026-08-21. All file:line anchors verified against the working tree at commit `305a70cf`.
**Execute:** commit-by-commit, in the order given. Every commit must pass `php -l` on touched PHP,
`node --check` on touched JS, and `ruby -c` on the Fastfile (ruby IS present in this container at
`/usr/local/bin/ruby`) before it is made (CLAUDE.md commit expectations).

> ⚠️ **Sequencing vs Waves 1–2:** Wave 1 edits `includes/song_importers.php` (Unicode/TXT region);
> Wave 2 edits `song_importers.php` (other regions) + the v2 editor API (`manage/editor/api2.php`,
> `manage/editor/save_song_core.php`, editor2 UI). **Wave 3 touches NONE of those files.** Its file
> set is fully disjoint: `manage/editor/index.php` (the LEGACY editor, one deletion at ~:1612 —
> not a Wave-2 file), the two `.htaccess` files, `tests/browser/router.php`,
> `appApple/fastlane/Fastfile`, the six slug-form manage pages, one new shared partial, and new
> tests. Wave 3 can therefore land before, between, or after Waves 1–2 without rebase risk.

---

## Verification verdicts (the six candidate groups)

| Candidate | Verdict | Wave-3 action |
|---|---|---|
| **#1579** apple-deploy CI broken | **REAL (split)** — 2 of the 3 diagnosed faults still in the tree (Fastfile); the 3rd (missing SDKs) is already fixed in-tree but never exercised; the "stopped triggering" follow-up comment is a **misdiagnosis** (it's the deliberate `paths:` filter) | C3 — Fastfile config fix (reachable); run-verification + upload re-arm = **owner-gated** |
| **#1858** Bootstrap double-load | **REAL** — legacy editor emits the JS bundle at `editor/index.php:1612` AND via `admin-footer.php:75` | C1 — remove the page's own emit + a tree-derived guard |
| **#1870** editable slug inputs | **REAL** — 11 always-visible slug inputs across 6 manage pages; server-side derive-on-blank already exists everywhere | C4 — shared "advanced" slug partial + guard |
| **#1871** venue timezone hand-set | **OWNER-GATED** — the derivation source **does not exist**: `tblPlaces` has no timezone column and the OSM/Nominatim geocoder doesn't return one; the issue itself conditions removal on verifying the source | §0.2 — post the verification finding + decision block; no code |
| **#1895** publisher picker asymmetry | **OWNER-GATED** — the issue is explicitly a product decision (A/B/C) and its own recommendation is **C: leave as-is**; code state verified unchanged | §0.3 — post the decision block; no code |
| **#1685 / #1719** dormant stub chains | **OWNER-GATED by design** (per this wave's brief) — both issues verified still open **and still accurate** against the tree | §0.4 — tracking hygiene confirmed; nothing to file, nothing to build |
| **#1906 remainder** `Options -Indexes` + `ServerSignature Off` | **REAL (split)** — the `-Indexes` *effect* is achievable safely via mod_rewrite (already load-bearing on this host); the literal directives are **NOT** safely wrappable (see §A.3 — `<IfModule>` does not guard against `AllowOverride`); `ServerSignature` = document-only (Apache default is already `Off`) | C2 — rewrite-based directory-404 + doc comment; no `Options`/`ServerSignature` directive |

**Score: 4 real code gaps (2 of them "split" — partial reach), 0 dropped-as-done, 4 owner-gated items.**

---

## §0 Owner-decision / no-code items (with evidence)

### §0.1 #1579 — the upload re-arm + run verification (the owner-gated half)

C3 below fixes the two Fastfile faults that are diagnosable from the failure logs. What it CANNOT do:

- **Verify the fix with a real run.** The build needs a `macos-26` runner + the org's live secrets
  (`APPLE_CERTIFICATE`, `ASC_*`). Secrets are confirmed *present* (the issue's log evidence shows
  `create_keychain`/`import_certificate`/`app_store_connect_api_key` succeeding), so no
  absent-secret skip-guard is needed — but only a supervised `workflow_dispatch` (lane=alpha) can
  prove the archive now succeeds.
- **Decide whether uploads stay armed.** `vars.APPLE_DEPLOY_ENABLED` is `true` (issue evidence).
  ⚠️ **Merging this branch to `alpha` will itself fire the workflow** — `appApple/fastlane/Fastfile`
  matches the `paths:` filter (`apple-deploy.yml:50-51`) — and if the fix works, that is a real
  TestFlight **internal** upload as a side effect of the merge. A later `beta` promotion re-arms
  **external** uploads (PR #1580's recorded trap).

**Decision block to post on #1579 (C5 close-out), in the standing 5-part shape:**
1. *Decision:* keep `APPLE_DEPLOY_ENABLED=true` (first post-fix alpha merge attempts a real
   TestFlight-internal upload) or flip it `false` until a supervised `workflow_dispatch` verifies
   the pipeline.
2. *Why owner:* only the owner can change repo Variables (Settings → Actions → Variables) and only
   the owner knows whether TestFlight traffic is wanted now.
3. *Options:* (a) leave `true` — zero-touch, but the verification run doubles as a live upload;
   (b) flip `false`, dispatch once manually, flip back — controlled, two UI touches; (c) do
   nothing — the fix sits unexercised (the current state, minus the fix).
4. *Recommendation:* **(b)** — the issue's own comment already flags that re-arming must be "a
   deliberate decision, not a side effect".
5. *Need back:* "a", "b", or "c". **Non-blocking** — C3 lands either way; only verification waits.

Also to post: the correction of the 2026-07-31 comment's misdiagnosis — "no longer triggers" is not
a trigger fault. Evidence: `origin/alpha` has carried the `paths: ['appApple/**', …]` filter since
commit `7d4948ba` (2026-07-11, verified via `git show origin/alpha:.github/workflows/apple-deploy.yml`);
the 29-July alpha pushes touched no `appApple/**` path, so not firing is the filter working as
designed (doc/web pushes must never fire a signed build). The five failures (7/10 July) all predate
`7d4948ba`, i.e. they ran WITHOUT the `Install required platform SDKs` step that now exists at
`apple-deploy.yml:136-141` — so fault 3 of the issue ("iOS 26.0 must be installed") is already
fixed in-tree, just never exercised.

### §0.2 #1871 — venue timezone: derivation source does not exist → owner decision

Verified against the tree:

- The venue form's timezone control: `manage/venues.php:727-733` (a `<select>` over
  `DateTimeZone::listIdentifiers()`, `:67`); stored in `tblOrgVenues.TimeZone`
  (`schema.sql:4839`), with a per-schedule override (`schema.sql:4902`). Service Mode's
  occurrence-end maths consumes it (`_EffTz` at `venues.php:486`; `serviceMode_occurrenceEndUtc()`).
- **`tblPlaces` has NO timezone column** (`schema.sql:128-155` — full column list verified:
  Provider/Osm*/DisplayName/Name/Suburb/City/County/Region/Country/CountryCode/Lat/Lng/PlaceType/
  timestamps only). `manage/places-api.php` contains zero timezone references; the geocoder family
  is OSM/Nominatim (`Provider … DEFAULT 'osm'`), and Nominatim does not return a timezone.

So deriving IANA tz from the linked place requires either a lat/lng→tz boundary dataset (a new,
large vendored dependency on shared hosting) or a new external service call — neither is a "small
cleanup", and #1871 itself says: *"Verify the places data actually carries a resolvable timezone
before removing the manual field"*. It does not. A CountryCode→tz map is disqualified by the
issue's own warning (multi-tz countries + Service Mode correctness is load-bearing).

**Decision block to post on #1871:** keep the manual field (status quo, recommended — it is
correctness-critical and currently explicit), vs accept a tz-boundary dependency, vs an external
lookup service. Recommendation: **keep manual**; close the issue as "verified: not derivable from
current data" or re-scope it to "prefill suggestion only" (e.g. default the select from the
browser's `Intl.DateTimeFormat().resolvedOptions().timeZone` on *create* — a UX nicety, not a
derivation, and trivially a separate small issue if wanted). Non-blocking.

### §0.3 #1895 — publisher create/edit picker asymmetry → the issue IS a decision

Code state re-verified: create arm uses the find-or-create picker
(`manage/songbooks.php:1245` `publisherResolvePickedOrCreate()`, attach at `:5604-5612`); edit
modal keeps the rich multi-publisher block (`#edit-publishers-block`, `:4088`) plus the quick
field. Exactly as the issue describes; nothing has drifted. The issue's own recommendation is
**C — leave as-is** ("set the primary on create, manage the rest on edit"), non-blocking.
**Action:** post the A/B/C decision block restating recommendation C; do not close unilaterally
(convergence direction is a product call). No code in this wave.

### §0.4 #1685 / #1719 — dormant stubs: tracking hygiene CONFIRMED, nothing to do

Per this wave's brief these are finish-or-retire owner decisions, not autonomous work. Verified:

- **#1685** (motd / captcha / ads dead settings chains): open and accurate. The X6 description
  rewrite is done (issue comment, commits `8fa5bb7f`→`648cdfde`); `motd` remains emit-only
  (verified: the only non-registry reference in the docroot is `api.php`'s `app_status` emit).
  Remaining scope (M2 motd feature, captcha/ads) is correctly recorded as unscheduled. **No action.**
- **#1719** (#778 phase-A `tblSongbookLanguages`/`tblSongLanguages` inert): open and accurate.
  Re-verified: `tblSongbookLanguages` referenced ONLY by `manage/includes/migration-registry.php`;
  `tblSongLanguages` only there plus the two mechanical sweeps the issue already names
  (`manage/duplicate-songs.php`, `includes/song_relocate.php`). No reader/writer has appeared.
  The three-way decision (finish phases B–E / label dormant / drop) is the owner's. **No action.**

Both get one line in the C5 close-out handoff ("confirmed open + accurate on 2026-08-21"), nothing
more — re-commenting "still true" on an unchanged issue is noise.

### §0.5 #1906 — `ServerSignature Off`: document-only (and why)

Two facts make the directive pointless-to-risky in `.htaccess`:
1. **Apache's default is already `ServerSignature Off`** — it only appears if a host explicitly
   enabled it, and the epic already marks this "needs-runtime-check (host/php.ini-level)".
2. The related `ServerTokens` (the `Server:` response header) is **server-config-only** — not
   settable from `.htaccess` at any `AllowOverride` level — so the reachable win is small even
   when the host misbehaves.

With C2's directory-404 in place, mod_autoindex pages (the main signature surface) can no longer
render, and the site's ErrorDocuments cover the themed error paths. **Spec: a comment in the
`.htaccess` security section documenting this reasoning + a one-line owner runtime check**
(`curl -sI https://…/nonexistent | grep -i server` on alpha) — no directive. This is option (b)
of the brief, chosen because option (a) is a trap (see §A.3).

---

## Commits (smallest / safest first)

### C1 — #1858: de-duplicate the legacy editor's Bootstrap JS + a tree-derived single-load guard

**Goal:** the Bootstrap bundle loads exactly once on `/manage/editor/`; a guard makes the whole
class (page emits what `admin-footer.php` already emits) unable to return. Binding rules: #36
(the `ihymns_bootstrap_*` helpers are the ONE source), CLAUDE.md web checkpoint #2 ("do not
re-load Bootstrap JS anywhere else"), #34 (guard derived from the tree, mutation-proven).

**The verified double-load:** `manage/editor/index.php:1612` (`<?= ihymns_bootstrap_js_script() ?>`)
AND its `require` of `admin-footer.php` at `:2268`, whose line 75 emits the same tag.
`ihymns_bootstrap_js_script()` (`includes/bootstrap_assets.php:203-219`) is a pure emitter — not
idempotent — so the browser parses/executes the bundle twice (second execution re-registers
Bootstrap's delegated document listeners; dropdown/modal double-fire bugs are the classic symptom).

**Change 1 — `manage/editor/index.php`:** delete the emit at `:1612` and its lead-in comment
(`:1609-1611`, "Bootstrap 5.3 JavaScript bundle — required for tabs… #1676: emitted by the shared
helper…"). Reword the section banner at `:1600-1604` ("JAVASCRIPT DEPENDENCIES … Bootstrap 5.3 JS
bundle … loaded from CDN") to state that Bootstrap JS arrives ONCE, from `admin-footer.php` at the
bottom of the page (the `editor2.php:382` comment is the exact model — copy its shape). Leave
`:2268`'s footer include untouched.

*Safety, verified in this planning pass (implementer: re-verify, it's two greps):*
- `grep -n 'bootstrap\.' manage/editor/index.php` → **zero** hits outside comments — no inline
  script on the page touches the `bootstrap` global at all.
- `editor.js` (classic script at `:1724`, runs before the footer) references `bootstrap` at exactly
  5 sites (`5309, 5347, 5926, 6499, 6606`) — ALL inside functions invoked on user interaction, and
  the toast helper even guards `typeof bootstrap === 'undefined'` (`:5309`). Nothing executes at
  parse time. Module scripts (`:2065, :2087`) run after document parse, i.e. after the footer's
  classic bundle. Moving the load point from `:1612` to `:2268` is therefore behaviour-preserving.

**Change 2 — new guard `tests/php/test-bootstrap-single-load.php`** (auto-run: `tools/run-php-tests.php`
globs `tests/php/*.php`, so no CI wiring needed — the mechanism rule #35 wants already exists):
- Derive the page list from the tree: every `*.php` under `appWeb/public_html/manage/` (recursive)
  plus `appWeb/public_html/includes/channel_gate.php`.
- **Strip PHP block + line comments AND HTML comments before matching** (the `editor2.php:382`
  comment literally names the function; an unstripped grep guard would false-positive — rule #34's
  "narrow enough not to fail on correct code").
- Assert: any file whose stripped source requires `admin-footer.php` must NOT itself call
  `ihymns_bootstrap_js_script(`. Companion assertion for CSS: any file requiring `head-libs.php`
  must not also call `ihymns_bootstrap_css_links(` (same drift class; bespoke-`<head>` pages like
  `editor2.php` legitimately call it because they *don't* include head-libs).
- Assert `admin-footer.php` itself still contains exactly one call (the guard must fail loudly if
  someone "fixes" a future dupe by deleting the footer's canonical emit instead).

**Mutation-proof (record the transcript in the commit body):** run the guard at `HEAD` *before*
Change 1 (or `git stash` the fix) → must go **red** on `editor/index.php` — the live bug is the
mutation test; apply the fix → green; then temporarily delete the footer's emit → red again;
restore.

**Verification:** `php -l` both touched files; `php tests/php/test-bootstrap-single-load.php`;
`node tests/test-vendor-sri.js` still green (it checks pinning/SRI, untouched); manual smoke on
the deployed page when next on alpha — open `/manage/editor`, exercise a dropdown, the History
modal (`#history-modal`) and a toast.
**Effort:** S.
**Issue action (in this commit's close-out):** close #1858 with the SHA + the mutation transcript.

---

### C2 — #1906 remainder: directory-listing kill via mod_rewrite (never the `Options` directive)

**Goal:** the *effect* of `Options -Indexes` — no directory under either docroot scope can ever
render an autoindex listing — delivered through machinery that **cannot 500 the site** on any
`AllowOverride` configuration, because it is machinery the site already fatally depends on
(mod_rewrite directives, incl. `R=404`, already run at `.htaccess:48,200,226`; if the host refused
them, nothing would work today). Binding rules: #34 (mutation-proven, tree-derived), #35 (the
router.php mirror is the existing agreement mechanism), and the brief's "do NOT spec a change that
could fatal the site" (see §A.3 for why literal option (a) is rejected).

**The exposure (verified):** `appWeb/public_html/.htaccess:168-170` L-stops any request whose
`%{REQUEST_FILENAME}` is `-f` **or `-d`** — so `/css/`, `/js/`, `/vendor/`, `/fonts/`,
`/.well-known/`, `/assets/` fall through to Apache's default directory handling; on a host whose
default is `+Indexes`, that is a listing. Independently, `manage/.htaccess` (`RewriteEngine On`
at `:20`) **replaces** the docroot rules for `/manage/*` (per-directory rewrite does not inherit),
and its generic clean-URL rule (`:43-46`) excludes `-d` — so `/manage/includes/`,
`/manage/editor/protos/` etc. have the same exposure, unprotected by anything in the docroot file.

**Change 1 — `appWeb/public_html/.htaccess`:** insert immediately BEFORE the `:168-170`
passthrough (i.e. between the AASA rewrite block and "STATIC ASSET PASSTHROUGH"):

```apache
# ==========================================================================
# DIRECTORY-LISTING KILL (#1906)
# A request that maps to a real DIRECTORY with no index.php of its own has
# no legitimate response — without this it would fall through the -d
# passthrough below into mod_autoindex, and whether that lists the
# directory's contents is a HOST default (+Indexes) we don't control.
# Deliberately mod_rewrite, NOT `Options -Indexes`: the Options directive
# 500s the ENTIRE site if the host's AllowOverride excludes `Options`
# (an <IfModule> wrapper does NOT prevent that — the directive is still
# parsed whenever the module is present), while mod_rewrite is already
# load-bearing in this file. Directories WITH an index.php (/, /manage/…)
# are excluded and keep working via DirectoryIndex.
# `^.+` (not `^`) exempts the docroot itself. ErrorDocument 404 below
# serves the themed SPA 404 body, matching #1905's contract.
# NOTE (#1906 also asked about ServerSignature Off): NOT set here —
# Apache's default is already Off, ServerTokens can't be set from
# .htaccess at all, and with listings dead the signature has nowhere
# user-visible left to render. Host check: `curl -sI /<nonexistent>`.
# ==========================================================================
RewriteCond %{REQUEST_FILENAME} -d
RewriteCond %{REQUEST_FILENAME}/index.php !-f
RewriteRule ^.+ - [R=404,L]
```

**Change 2 — `appWeb/public_html/manage/.htaccess`:** the same 3-line rule (with a short comment
pointing at the docroot block for the full rationale), inserted after the `^editor/api$` rule
(`:31`) and BEFORE the generic clean-URL block (`:34`). The `index.php !-f` exclusion keeps
`/manage/` and `/manage/editor/` (both have an `index.php`) fully working; `/manage/includes/`
and other code directories 404. The docroot's `ErrorDocument 404` is inherited, so the body stays
themed.

**Change 3 — mirror in `tests/browser/router.php`:** the dev router already mirrors the
`-f`/`-d` passthrough (`:142-150`) and the asset-prefix 404 (`:172-195`) with rule-#35 comments.
Add the same behaviour: if the resolved path `is_dir()` and `!is_file($dir.'/index.php')` and the
URI path is non-root → emit 404. Keep the comment cross-reference both ways.

**Change 4 — mechanism, not comment (rule #35/#34):** extend
`tests/php/test-route-allowlist-coverage.php` (the existing test that already holds `.htaccess`
and `router.php` in byte-agreement for the scanner alternation) with an assertion that all three
files carry the directory-404 logic: the exact `RewriteCond %{REQUEST_FILENAME} -d` +
`RewriteCond %{REQUEST_FILENAME}/index.php !-f` + `[R=404` triple in BOTH `.htaccess` files, and
the `is_dir` + `index.php` check in `router.php`. **Mutation-prove:** delete the rule from one
file → red; restore.

**Legit-URL audit (already done in this pass; implementer re-checks against the final diff):**
`/` (root — exempted by `^.+`); `/manage`, `/manage/` (index.php exists → excluded; also governed
by manage/.htaccess); `/manage/editor`, `/manage/editor/` (editor/index.php exists → excluded);
every rewritten endpoint (`/api`, `/og-image`, `/song-media/*`, `/audio/*`, `service-worker.js`,
`sitemap.xml`, AASA) resolves to files, untouched; SPA routes are non-directories, untouched.
Rule #33 sweep: `grep -rn 'href="/css/"' … ` style bare-directory links → none exist.

**Verification:** the extended coverage test + full `tools/run-php-tests.php`; after next alpha
deploy, `curl -s -o /dev/null -w '%{http_code}' https://<alpha>/css/` → 404, `/manage/editor/`
→ 200, `/` → 200. (The PHP dev-server harness `tests/browser/` exercises the router.php mirror.)
**Effort:** S.
**Issue action:** tick/annotate the `Options -Indexes` checkbox item on epic #1906 with the SHA and
the "rewrite, not Options — here's why" one-liner; note the ServerSignature document-only outcome.

---

### C3 — #1579: fix the two live Fastfile faults (destination conflict + unauthenticated automatic signing)

**Goal:** the `alpha`/`beta`/`release` lanes stop failing at `build_app` for the two reasons the
run logs prove, using only repo-reachable config — no new secrets, no `match`, honouring the owner
instruction already encoded in the Fastfile header (automatic provisioning via ASC API key, #1400).
Binding rules: #34-adjacent honesty (we cannot run this here — say so everywhere), §0.1 for the
owner-gated half.

**Fault 1 — conflicting `-destination` flags** (issue's gym summary: gym default
`generic/platform=visionOS` + xcargs `-destination 'generic/platform=iOS'` on one invocation).
Root cause in the tree: `archive_scheme()` (`appApple/fastlane/Fastfile:87-100`) smuggles the
destination through `xcargs` while `build_app` gets no `destination:` option, so gym auto-picks
its own from the multi-platform scheme (`supportedDestinations: [iOS, macOS, visionOS]`) and BOTH
end up on the `xcodebuild` command line.

*Fix:* make destination a first-class gym option and stop passing it via xcargs:

```ruby
def archive_scheme(scheme:, destination:)
  build_app(
    project: XCODEPROJ_PATH,
    scheme: scheme,
    configuration: "Release",
    export_method: "app-store",
    output_directory: BUILDS_DIR,
    output_name: "#{scheme}.ipa",
    clean: true,
    destination: destination,      # gym now emits exactly ONE -destination
    xcargs: signing_xcargs         # fault-2 fix below
  )
end
```

Call sites (`:219-220`): `archive_scheme(scheme: "iHymns",   destination: "generic/platform=iOS")`
and `archive_scheme(scheme: "iHymnsTV", destination: "generic/platform=tvOS")` — the tvOS one made
explicit too (auto-detect is what produced the visionOS surprise; the old ":no destination needed
for single-platform" comment at `:82-86` gets updated to say why explicit-everywhere now).

**Fault 2 — automatic signing has no ASC credentials at archive time** (`No Accounts` /
`No profiles for 'app.ihymns'`). Root cause: `-allowProvisioningUpdates` (xcargs, `:88`) makes
`xcodebuild` itself talk to App Store Connect, but nothing hands it the API key —
`app_store_connect_api_key` (`:186-194`) only populates fastlane's lane context, which
`pilot`/`deliver` read; **gym does not forward it to xcodebuild**. On a headless runner with no
Xcode accounts, provisioning then has no credential at all — exactly the logged errors. This is
the documented xcodebuild pairing: `-allowProvisioningUpdates` +
`-authenticationKeyPath/-authenticationKeyID/-authenticationKeyIssuerID`.

*Fix (all in the Fastfile, mirroring its existing temp-file discipline at `:162-176`):*

```ruby
def asc_key_file_path
  @asc_key_file ||= File.join(Dir.tmpdir, "ihymns-asc-#{SecureRandom.hex(4)}.p8").tap do |p|
    File.write(p, ENV.fetch("ASC_API_KEY"))   # raw .p8 contents, per the :179-194 doc-block
  end
end

def signing_xcargs
  [
    "-allowProvisioningUpdates",
    "-authenticationKeyPath #{asc_key_file_path.shellescape}",
    "-authenticationKeyID #{ENV.fetch('ASC_KEY_ID').shellescape}",
    "-authenticationKeyIssuerID #{ENV.fetch('ASC_ISSUER_ID').shellescape}"
  ].join(" ")
end
```

Extend BOTH cleanup hooks (`after_all` `:289-291`, `error` `:293-295`) with
`FileUtils.rm_f(@asc_key_file) if @asc_key_file` — the .p8 is a secret and must not outlive the
run, same doctrine as the .p12 at `:171-176`. Update the file's header + the
`import_distribution_certificate` doc-block (`:142-151`), which currently *claims* the ASC key
participates in signing — after this fix the claim is finally true (rule #35's "documentation is
not a mechanism" — make the mechanism match the doc).

**No workflow YAML change.** The `Install required platform SDKs` step (`apple-deploy.yml:136-141`)
already covers fault 3 (in-tree since `7d4948ba`, post-dating all five failed runs); the env block
(`:166-177`) already exports `ASC_KEY_ID`/`ASC_ISSUER_ID`/`ASC_API_KEY` to the lane.

**Verification (honest scope):** `ruby -c appApple/fastlane/Fastfile` in-container (ruby present);
diff-review that `-destination` appears nowhere in any xcargs string. **A real run is owner-gated**
— §0.1's decision block covers it; the commit message + issue comment must both state "unexercised
until a supervised dispatch". Do NOT claim the pipeline fixed — claim the two diagnosed faults
removed.
**Effort:** M.
**Issue action:** comment on #1579 with the SHA, the §0.1 misdiagnosis correction (paths-filter
evidence), and the §0.1 decision block. Leave the issue OPEN until a green supervised run.

---

### C4 — #1870: derive-by-default slug fields — one shared partial, 11 call sites, one guard

**Goal:** no manage form shows an always-on editable slug input; the slug derives from the
name/title (which the server already does on every one of these forms) and the override lives
behind a collapsed "Edit slug (advanced)" disclosure. Rule #44 (derive or omit), the modularity
rule (ONE partial, never 11 pasted collapse blocks), rule #33 (existing `/…/<slug>` URLs must keep
resolving — achieved by *preserve-on-edit*), rule #34 (guard tree-derived + mutation-proven).

**Verified inventory — the 11 inputs (6 pages):**

| Page | Create-form input | Edit-form input | Server derive-on-blank (verified) |
|---|---|---|---|
| `manage/catalogues.php` | `:586` | — (no edit slug field; don't add one) | `:196` `$slugFor($title)` + collision suffix `:273-281` |
| `manage/organisations.php` | `:698` ("auto") | `:779` (**`required`**) | create `:106`; edit requires non-empty `:165-175` |
| `manage/publishers.php` | `:474` | `:545` (+ alias-fallback form-text `:546`) | core `publisher_admin.php:138-159` (optional, `publisherSlugEnsureUnique()`) |
| `manage/songbook-series.php` | `:754` (`#create-slug`) | `:842` (`#edit-slug`) | `:223` / `:368` `$slugFor($name)` |
| `manage/tunes.php` | `:573` | `:660` (`#edit-tune-slug`) | core `tune_helpers.php` `ihymns_tune_slugify()` |
| `manage/works.php` | `:1216` (`#create-slug`) | `:1378` (`#edit-work-slug`) | `:567` / `:661` `$slugFor($title)` |

**No server-side behaviour changes.** Empty create slug already derives everywhere; edit forms
submit the prefilled value → preserved. This commit is UI + one exception: drop the client-side
`required` from `organisations.php:779` (a `required` control inside a *closed* `<details>` blocks
submit with focus aimed at an invisible field; the server's own `'Slug is required.'` at `:175`
already answers the empty case — status/server-truth over client claim, rule #35's spirit).

**New shared partial — `appWeb/public_html/manage/includes/slug-field.php`** exposing ONE function
(annotated to project standard — ELI5 + detailed registers, MDN `<details>` link, `#1870`):

```php
ihymns_slug_advanced_field(array $o): string
// keys: id (?string), value (string ''), maxlength (int), pattern (?string),
//        placeholder (?string), small (bool — form-control-sm sizing),
//        help (?string, pre-escaped HTML allowed for the existing <code> hints)
```

Renders a native `<details class="slug-advanced">` (no Bootstrap-JS dependency, keyboard/a11y
built in) whose `<summary>` reads **"Edit slug (advanced)"** with `small text-muted` styling,
containing the same `<input type="text" name="slug" …>` each site has today (ids preserved —
they are load-bearing, see below) and the site's existing `form-text` hint passed through `help`.
Create sites pass `value: ''` (closed + empty → submits empty → server derives); edit sites pass
the current slug (closed + prefilled → submits unchanged → preserved). Add a ~4-line
`.slug-advanced` block to `appWeb/public_html/css/admin.css` (summary cursor + spacing) — no
per-page styles.

**Call-site mechanics:** each of the 11 sites keeps its existing grid column `<div>` and replaces
the `<label>+<input>(+form-text)` inside it with the partial call (a closed `<details>` collapses
to one summary line, so the row layout tightens without grid surgery). Each page adds ONE
`require_once __DIR__ . '/includes/slug-field.php';` near its other includes.

**Load-bearing JS that must keep working (ids preserved is the whole trick):**
- `songbook-series.php:1135-1141` — create auto-slugify-as-you-type into `#create-slug` with its
  `userTouched` latch: keeps working against the hidden input; keep it (it makes the advanced
  panel show the *derived* value when opened — genuinely better).
- Edit-modal prefills: `publishers.php:872`, `tunes.php:1088`, `works.php:1984` — all
  `document.getElementById(…).value = row.slug` — untouched by id preservation.

**New guard — `tests/php/test-slug-field-partial.php`** (auto-globbed): comment-strip every
`appWeb/public_html/manage/*.php`; assert (1) zero raw `<input … name="slug"` markup outside
`manage/includes/slug-field.php`; (2) the partial's input still carries `name="slug"` and sits
inside `<details>`; (3) every page that POSTs a `slug` (derive the list by grepping handlers for
`$_POST['slug']`) either calls `ihymns_slug_advanced_field(` or is a known non-form consumer —
the list of pages is DERIVED, never typed (rule #34; the floor-list pattern of
`test-component-label-sites.js` is the model). **Mutation-prove:** re-inline one input → red;
strip `name="slug"` from the partial → red; restore.

**Verification:** `php -l` on all 7 touched PHP files + the partial; the new guard + full
`tools/run-php-tests.php`; `node --check` on nothing (no JS edits); on alpha: create a throwaway
tune with the panel closed (slug derives), rename a work WITHOUT opening the panel (slug
preserved — rule #33: `/work/<slug>` link unchanged), override a slug via the panel (respected).
**Effort:** M (mechanical × 11, but each site is a 5-line swap).
**Issue action:** close #1870 with the SHA, the inventory table above, and the explicit note that
derive-on-create/preserve-on-edit was already server-truth — this commit removed the vanity
*controls* (rule #44), it did not touch slug storage.

---

### C5 — close-out: docs, tracker, decisions (standing-tasks pass)

- **CHANGELOG.md** — one entry per shipped item (#1858, #1906-Indexes, #1579-config, #1870).
- **Issues** — the per-commit actions above, plus: post §0.2's decision block on #1871, §0.3's on
  #1895; epic #1906 checklist annotations; #1863 epic gets a "child #1870 done" tick.
- **`.claude/`** — handoff entry (including the §0.4 "confirmed accurate, no action" lines for
  #1685/#1719 so the next session doesn't re-verify them); note in `MEMORY.md`/brief if the
  slug-partial or single-load guard become reusable conventions.
- **Wiki** — only if the deploy/setup pages describe `.htaccess` behaviour (check
  `iHymns.wiki/` for a hardening/deploy page; the directory-404 changes observable behaviour of
  bare directory URLs).
- **No code in this commit.**
**Effort:** S.

---

## §A Adversarial review (what would make this wave wrong)

**A.1 — C1 breaks a hidden parse-time Bootstrap consumer.** Mitigated by the two greps recorded in
C1 (zero `bootstrap.` in page-inline scripts; all 5 `editor.js` uses lazy + one explicitly
guarded). Residual: a `<script src>` on the page whose top level uses `bootstrap`
(`external-link-detect.js`, `external-links-editor.js`, `place-search.js`, `combobox-a11y.js`,
`editor.js`, `propresenter-export.js`, `format-export.js`, `protobuf.min.js`,
`pp7-proto-static.js`) — implementer runs `grep -l '^\s*\(new \)\?bootstrap\.' ` over that set
before committing; any hit converts the fix to "keep `:1612`, suppress the footer's emit via a
page flag" (NOT expected — the sweep in this pass found none in the three checked).

**A.2 — C1's guard is wrong-but-green (rule #34's repeated lesson).** Two designed-in failure
modes: comment mentions (`editor2.php:382` names the function in prose → MUST comment-strip) and
an over-broad "no page may call the helper" (bespoke-`<head>` pages legitimately call the CSS
helpers → the assertion is scoped to *JS-helper + footer co-presence* and *CSS-helper + head-libs
co-presence*, never a blanket ban). The mutation transcript is mandatory in the commit body.

**A.3 — C2's rejected alternative: why `<IfModule>` around `Options -Indexes` is NOT safe.** An
`AllowOverride` violation is raised when the directive is *processed*, and `<IfModule
mod_autoindex.c>` evaluates true on effectively every Apache (autoindex is standard) — the wrapper
only guards module *absence*, not override *permission*, so the wrapped directive still 500s the
entire site on a host whose `AllowOverride` excludes `Options`. Same class for
`ServerSignature` (Override: All). This is exactly the "could fatal the site" the brief bans;
the mod_rewrite equivalent is the only self-evidencing-safe path (its directives already gate
every request the site serves). Anyone tempted to "just add the one-liner" later should hit this
paragraph — it is also condensed into the `.htaccess` comment C2 ships.

**A.4 — C2 404s a directory URL something depends on.** The `index.php !-f` exclusion protects
every DirectoryIndex-served URL by *construction* rather than by allow-list (safer than the
`REQUEST_URI !^/manage` variant considered and rejected — that one 404'd bare `/manage` on hosts
where per-dir inheritance differs). The rule-#33 sweep (grep for emitted hrefs ending in `/` that
map to real directories) found none; the router.php mirror keeps the dev harness honest; the alpha
curl matrix in C2 is the runtime proof. Residual: a third-party integration hitting a bare
directory URL out-of-band — unobservable from the repo, reversible in one line if a 404 spike
shows in logs.

**A.5 — C3 "fixes" the wrong thing because the July logs are stale.** Real risk — the five runs
predate the SDK-install step, and after it the failure surface may differ. Contained by scope
discipline: both changes correct *provable, still-present* defects (the xcargs `-destination` is
in today's tree; nothing in today's tree hands the ASC key to xcodebuild — both re-verifiable by
grep), neither can make a currently-failing build fail *worse*, and the issue stays open pending a
supervised run. The known residual (`IDERunDestination: supported platforms … empty`) is named in
the issue comment so the next log-reader isn't surprised.

**A.6 — C3's merge-side-effect.** Landing this branch on alpha fires apple-deploy (the Fastfile is
under `appApple/**`). With the kill-switch `true`, a *successful* fix = a real TestFlight-internal
upload on merge day. This is §0.1's decision block — the plan deliberately does NOT try to be
clever (e.g. sneaking a `paths` exclusion for the Fastfile would break the workflow's own
correctness). The owner hears about it BEFORE the merge, in the issue and the handoff.

**A.7 — C4 strands a curator who needs the slug field.** The field is hidden, not removed — one
click on a visible, labelled summary. The riskier inverse — silently *changing* slugs — is
structurally excluded: no server code changes, closed-details edit forms round-trip the stored
value byte-identically. Watch-item: any future form that *renames* its name/title field and
expects the slug to re-derive on edit will find it preserved instead — that is rule #44-correct
(stable public URLs beat matching labels, `publishers.php:546`'s own hint documents the alias
fallback) but worth the one line in the partial's doc-block.

**A.8 — C4's `<details>` vs. browser validation.** The one `required` slug (organisations edit)
is handled by dropping the client attribute and keeping the server error (C4 body). No other slug
input carries `required` (verified in the inventory sweep). `pattern=` on a hidden-but-submitted
prefilled input can only block if the STORED slug already violates the pattern — impossible for
server-minted slugs; if a legacy hand-typed row somehow does, the panel is one click away and the
browser will point at the opened field.

**A.9 — Scope discipline.** Wave 3 must NOT drift into: the #1906 child issues (admin CSP,
og-image gate, auth rate limits — separate, some auth-sensitive), the #1863 picker rollouts beyond
#1870 (e.g. Copyright Holder → tblPublishers is its OWN audit line), or "while I'm in the
Fastfile" workflow refactors. Anything discovered en route gets an issue at the moment of
discovery (standing-tasks §2a) and stays out of these commits.

---

## Definition of done

- [ ] C1–C4 landed as four atomic commits on `claude/ilyrics-identity-work-model`, each with
      `php -l` / `node --check` / `ruby -c` clean on its touched files, in the given order.
- [ ] Both new guards + the extended coverage test green via `php tools/run-php-tests.php`, with a
      **mutation transcript** (guard shown red against the broken state) recorded in each guard's
      commit body — a guard whose first green was never challenged doesn't count (rule #34).
- [ ] Full existing suites green: `php tools/run-php-tests.php`, the `tests/*.js` node suites
      Wave 0's baseline ran (at minimum `test-vendor-sri.js`, `test-manage-php-urls.js`,
      `test-editor-deep-links.js` — C2/C4 touch routing and manage markup).
- [ ] Bootstrap bundle emitted exactly once on `/manage/editor` (view-source count on alpha).
- [ ] `curl` matrix from C2 verified on the next alpha deploy (404 on `/css/`, 200 on `/`,
      `/manage`, `/manage/editor/`).
- [ ] #1858 and #1870 CLOSED with SHAs + evidence; #1579 commented (fix SHA + misdiagnosis
      correction + decision block) and left open; #1871 and #1895 carry their decision blocks;
      #1906 epic checklist updated; #1685/#1719 confirmations in the handoff only.
- [ ] No edits to `includes/song_importers.php`, `manage/editor/api2.php`,
      `manage/editor/save_song_core.php`, `manage/editor/editor2.php`, or any Unicode-handling
      file (Waves 1–2 territory) — verify with `git diff --name-only` before each push.
- [ ] CHANGELOG + handoff + `.claude/` docs updated (C5); wiki checked for `.htaccess`-describing
      pages.

---

## Executive summary

Six candidate groups verified against the tree + tracker: **4 real code gaps** land in four
commits — the #1858 Bootstrap double-load (legacy editor, delete `:1612` + a comment-stripped
tree-derived single-load guard), the #1906 `-Indexes` remainder (mod_rewrite directory-404 in both
`.htaccess` files + router.php mirror — the literal `Options`/`ServerSignature` directives are
rejected as fatally unsafe under unknown `AllowOverride`, with `ServerSignature` document-only
since Apache defaults it Off), the #1579 apple-deploy Fastfile faults (single gym `destination:`
+ the missing xcodebuild ASC authentication-key trio — run-verification and upload re-arm stay
owner-gated, and the "stopped triggering" comment is corrected as paths-filter-by-design), and
#1870's 11 always-visible slug inputs across 6 manage pages (one shared `<details>` partial,
derive-on-create / preserve-on-edit already being server truth, plus a mutation-proven guard).
**0 candidates dropped as already-done; 4 owner-gated:** #1871 (venue tz — verified underivable:
`tblPlaces` carries no timezone), #1895 (picker asymmetry — the issue is itself an A/B/C product
decision, recommendation C), and #1685/#1719 (dormant stubs — confirmed open + accurate,
finish-or-retire is the owner's call). Effort: S+S+M+M+S; no overlap with Wave 1–2 files.
