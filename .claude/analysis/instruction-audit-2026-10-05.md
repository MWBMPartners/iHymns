# Instruction-file audit against Anthropic's current guidance (2026-10-05)

## 1. How to read this

Each row is one instruction from your AI instruction files, as they stood before today's session, with one verdict: **DELETE**, **REWRITE** or **KEEP**, and the reason in plain English.
Every DELETE quotes the Anthropic sentence that justifies it. A REWRITE quotes one where it exists, or says "no source". A KEEP only quotes one where it adds something. Safety and permission rules have no verdict: they are in section 3, for you to decide.
Line numbers are for the before-today copies of `CLAUDE.md`, `AGENTS.md`, `standing-tasks.md` and `device-level-rules.md` (today's polish-check additions are not audited). The other files are unchanged in the repo. Headings, blank lines, date stamps and descriptions that tell the reader to do nothing have no row.
"KEEP" on a code convention means no source supports changing it. I did not check every convention against the code, only the facts named in the "Why" column.

Sources: [P4] Prompting best practices · [P5a] What's new in Claude Opus 5.5 · [P5b] Prompting Claude Opus 5.5 · [P6] Prompting Claude Opus 5 · [P7] Prompting Claude Sonnet 5.5 · [P8] How Claude remembers your project · [P9] Best practices for Claude Code. Quotes are copied from `/tmp/claude-0/audit/guidance-quotes.md`; nothing is attributed to the three claude.dev blog posts, which could not be read here.

**2405 lines in (the 19 audited files, counted with `wc -l`). 511 instructions assessed: 69 deletes, 69 rewrites, 348 keeps, 25 for you to decide, plus 5 additions.**

Per file:

| File | Lines | Assessed | DELETE | REWRITE | KEEP | For you to decide |
|---|---:|---:|---:|---:|---:|---:|
| CLAUDE.md | 243 | 145 | 39 | 15 | 82 | 9 |
| AGENTS.md | 75 | 24 | 0 | 4 | 19 | 1 |
| standing-tasks.md | 205 | 47 | 1 | 6 | 40 | 0 |
| device-level-rules.md | 54 | 11 | 0 | 4 | 7 | 0 |
| standing-directives.md | 306 | 77 | 10 | 11 | 51 | 5 |
| project-rules.md | 600 | 88 | 13 | 11 | 60 | 4 |
| agents/deep-architect.md | 8 | 5 | 0 | 2 | 3 | 0 |
| agents/quick-edits.md | 8 | 5 | 0 | 1 | 4 | 0 |
| prompt-polish/SKILL.md | 88 | 24 | 1 | 3 | 20 | 0 |
| prompt-polish/references/fable-5.md | 227 | 13 | 1 | 2 | 10 | 0 |
| prompt-polish/references/opus-4-8.md | 218 | 12 | 1 | 2 | 9 | 0 |
| prompt-polish/agents/openai.yaml | 4 | 1 | 0 | 0 | 1 | 0 |
| .OpenAI/README.md | 23 | 3 | 0 | 0 | 3 | 0 |
| .OpenAI/CONTEXT.md | 45 | 6 | 0 | 1 | 4 | 1 |
| .OpenAI/MEMORY.md | 32 | 10 | 2 | 0 | 6 | 2 |
| whats-new-style.md | 58 | 10 | 0 | 2 | 8 | 0 |
| admin-plain-english.md | 83 | 11 | 0 | 0 | 11 | 0 |
| .claude/README.md | 29 | 10 | 0 | 4 | 5 | 1 |
| sessions/README.md | 99 | 9 | 1 | 1 | 5 | 2 |
| **Total** | **2405** | **511** | **69** | **69** | **348** | **25** |

Read but not counted (no instructions to the assistant): the prompt-polish skill's `README.md` (94 lines), `CHANGELOG.md` (23), `LICENSE` (21) and `VERSION` (1).

### What was flagged

- **Verify-twice rules** (your rule 3). The cross-tool review loop and its copies have no end point: AGENTS.md:53-54, device-level-rules.md:51-52, standing-directives.md:271, .OpenAI/CONTEXT.md:42-43 (all REWRITE, to add an exit). The "FULL review on return" step: AGENTS.md:56-57, device-level-rules.md:44-45, standing-directives.md:296-298 (REWRITE). The stand-in reviewer when Codex is out: standing-directives.md:278-279 (REWRITE). Restatements of the loop: standing-directives.md:147-150 and CLAUDE.md:212 (DELETE), project-rules.md:343 (REWRITE). Final self-check steps in the prompt-polish skill: SKILL.md:52, fable-5.md:219-227, opus-4-8.md:210-218 (DELETE). Kept with a flag: standing-directives.md:264-266 (you asked for the review), standing-directives.md:213-215 (a narrow wording check), fable-5.md:32-46 and 129-135 (advice for Fable prompts).
- **Not verify-twice, so kept as evidence**: CLAUDE.md:180, standing-directives.md:31-32, 54-60, 282-283, standing-tasks.md:41-44, 127-128, quick-edits.md:7-8. These run a real check (tests, syntax checks, CI guards, a command against the remote).
- **Severity filters** (your rule 4): standing-tasks.md:132 "Ban style findings", fable-5.md:207-216, opus-4-8.md:127-132 (all REWRITE). standing-tasks.md:134-135 could be read as one but is a truth rule, so KEEP. One tension to know about: [P9] says "Tell the reviewer to flag only gaps that affect correctness..." while [P6] says report everything. I used the [P9] line only for **when to stop** the review loop, never for what to report.
- **"Don't overthink" / "don't reason" rules** (your rule 5): **none found** in any of the 19 files. The opposite exists once: standing-directives.md:20-22 "Think hard before acting" (REWRITE, to use the effort setting).
- **Role lines** (your rule 6): deep-architect.md:7 and quick-edits.md:7 are KEEP. The guidance supports roles ([P4]) and no source supports deleting one. Three lines in the prompt-polish skill that call role lines junk are REWRITE for the same reason.
- **Truth rules** (your rule 8): all KEEP, including copies. The first-pass inventory had marked project-rules.md:598-600 for deletion; overridden here. (It had also marked both agent role lines for deletion; overridden under your rule 6.)

## 2. Verdicts, one table per file

### CLAUDE.md

`.claude/CLAUDE.md`, as it stood before today's polish-check line — 243 lines. 39 DELETE, 15 REWRITE, 82 KEEP, 9 for you to decide.

About 123 KB and 15,900 words in 243 lines, because most rules are a single very long line. The "under 200 lines" target in [P8] is about how much loads at the start of every session, so the line count understates the problem.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| CLAUDE.md:7 | "This applies to EVERY assistant working in this repository" | KEEP | Says the rule covers every AI tool and every kind of output. Short and true. | no source |
| CLAUDE.md:9 | "Write in plain, everyday English — in chat replies, commit messages, code comments, issue text, docs, and everywhere else." | KEEP | Your standing plain-English rule, with a good worked example of the wording you want. | [P6] "Positive examples of the communication style you want tend to be more effective than instructions about what not to do." |
| CLAUDE.md:9 | "Using more words is fine, and preferred, when it makes the meaning easier to understand." | REWRITE | Current Opus models already write longer replies than earlier ones, and the guidance says to ask for brevity outright. Keep "explain the term", but balance it: "explain what the reader needs, and cut what they do not". | [P4] "Prompt explicitly for conciseness instead." |
| CLAUDE.md:9 | "This does not lower the technical precision of the work; it only changes how we explain it." | KEEP | Stops plain wording being read as permission to simplify the work itself. | no source |
| CLAUDE.md:9 | "It applies to technical readers too" | KEEP | The one addition from your latest ask; still needed. | no source |
| CLAUDE.md:13 | "When a piece of UI, logic, or data exists in more than one place, extract it into a shared module." | KEEP | The core rule of the repo. Short. | no source |
| CLAUDE.md:17 | "every `/manage/*.php` page MUST use the shared partials" | KEEP | Still accurate. The dated "Corrected 2026-09-07" note is history, but it is under half the bullet, so by your rule it stays KEEP. | no source |
| CLAUDE.md:18 | "cross-platform (iOS / iPadOS / tvOS) code lives in shared Swift packages or frameworks." | KEEP | Platform rule. Short. | no source |
| CLAUDE.md:19 | "shared UI + domain logic in shared modules" | KEEP | Platform rule. Short. | no source |
| CLAUDE.md:20 | "a target of the Android codebase; shares the Android sharedModules" | KEEP | Platform rule. Short. | no source |
| CLAUDE.md:24 | "Before adding code on `/manage/*` or `/` (main app), review this list:" | KEEP | Tells Claude when the checkpoints apply. | no source |
| CLAUDE.md:26 | "The page supplies `$activePage`; the nav does the rest." | KEEP | Checkpoint 1. Code convention. | no source |
| CLAUDE.md:27 | "Do not render your own; do not re-load Bootstrap JS anywhere else." | KEEP | Checkpoint 2. Code convention. | no source |
| CLAUDE.md:28 | "`manage/includes/head-favicon.php` (admin) / the `<link>` block in `index.php` (main site)" | KEEP | Checkpoint 3. The file exists. | no source |
| CLAUDE.md:29 | "Pages MUST call `isAuthenticated()`, `requireAdmin()`, or `userHasEntitlement()` — never reinvent the check." | KEEP | Checkpoint 4. Security convention. | no source |
| CLAUDE.md:30 | "Never instantiate PDO / mysqli directly." | KEEP | Checkpoint 5. Security convention (bound parameters). | no source |
| CLAUDE.md:31 | "Never try to server-personalise a cached fragment" | KEEP | Checkpoint 6. Explains a real constraint (shared cache) that the code alone does not make obvious. | no source |
| CLAUDE.md:32 | "Any new "save for offline" button uses `data-song-download` or `data-songbook-download`" | KEEP | Checkpoint 7. Code convention. | no source |
| CLAUDE.md:33 | "Never query `tblContentRestrictions` directly from a page or an API handler." | KEEP | Checkpoint 8. Code convention. | no source |
| CLAUDE.md:34 | "`$LICENCE_TYPES` map in `organisations.php` today, will migrate to `tblLicenceTypes` (#459)." | REWRITE | Out of date. The move has happened: organisations.php now fills $LICENCE_TYPES from includes/licence_registry.php (organisations.php:27 and :85), and project-rules.md:415 calls that file the one reader of the licence list. Point at licence_registry.php instead. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| CLAUDE.md:35 | "`$ENTITLEMENT_LABELS` map in `manage/entitlements.php`" | KEEP | Checkpoint 10. Code convention. | no source |
| CLAUDE.md:36 | "Any new card-list / chip-list editor that pastes a URL and wants to pre-select a provider MUST consume this module" | KEEP | Checkpoint 11. Code convention. | no source |
| CLAUDE.md:37 | "nothing in `/manage/*` or `js/modules/*` should hard-code a provider key list." | KEEP | Checkpoint 12. Code convention. | no source |
| CLAUDE.md:38 | "opt in via `<table class="admin-table-responsive">`" | KEEP | Checkpoint 13. Code convention. | no source |
| CLAUDE.md:39 | "Songs that are part of a work render the "Part of work" panel via the shared partial — don't roll your own grouping UI." | KEEP | Checkpoint 14. Code convention. | no source |
| CLAUDE.md:40 | "Any new entity that grows external links gets its own `tbl<Entity>ExternalLinks` table, not a generic FK column." | KEEP | Checkpoint 15. Code convention. | no source |
| CLAUDE.md:41 | "Never hardcode `data-bs-theme="dark"` on a new admin page" | KEEP | Checkpoint 16. Code convention. | no source |
| CLAUDE.md:42 | "Song reads are LIVE MySQL — there is NO songs.json file cache" | KEEP | Rule 17. Mostly substance, little history. | no source |
| CLAUDE.md:43 | "public-facing prose links inherit the global `<a>` default in `app.css` Section 2.5" | REWRITE | Rule 18. The rule is sound (no underline by default; an opt-in "Emphasise Links" setting adds colour and underline; keep the key, attribute and colours in step; name the guard test). But most of the words are the reversal story and the contrast-ratio working. Keep the rule, move the working to the issue. | [P9] "Long explanations or tutorials" |
| CLAUDE.md:44 | "every `appWeb/.sql/migrate-*.php` that creates a table or adds a column MUST also append the matching declaration to `appWeb/.sql/schema.sql` in the same commit." | KEEP | Rule 19. Code convention with CI guards. | no source |
| CLAUDE.md:45 | "any growable / moderation vocabulary is `VARCHAR` (app-validated against a central map), never `ENUM`" | KEEP | Rule 20. Code convention. | no source |
| CLAUDE.md:46 | "Per-line lyric enrichment anchors on `tblLyricLines.Id`" | KEEP | Rule 21. Code convention. | no source |
| CLAUDE.md:47 | "never re-fork the normalise / levenshtein / Jaccard / blend logic" | KEEP | Rule 22. Code convention. | no source |
| CLAUDE.md:48 | "Never hard-code a theme list or re-seed ad-hoc" | KEEP | Rule 23. Code convention. | no source |
| CLAUDE.md:49 | "presentation only, never a storage merge" | KEEP | Rule 24. Code convention. | no source |
| CLAUDE.md:50 | "`tblLyricLines` is the source of truth for lyric lines — ONE read path, ONE write path" | KEEP | Rule 25. Code convention. Its sentence about the destructive column drop is listed separately under "For you to decide". | no source |
| CLAUDE.md:52 | "Service Mode (congregation Live-Follow) — channel-scoped + dormant-gated" | REWRITE | Rule 26. Most of it is the story of a "Still TODO" line going stale twice, plus status that keeps changing (the rule itself says to trust git log over it). Keep the design rules: filter by Channel in every query, rate-limit per presence token not per IP, never gate on location, one helper core. Move status and history out. | [P9] "Information that changes frequently" |
| CLAUDE.md:54 | "`Abbreviation` is the SongId prefix" | KEEP | Rule 27. Code convention. | no source |
| CLAUDE.md:56 | "Extensible gating registry — caps live in `TIER_CAPS`" | REWRITE | Rule 28. Keep the rule (a new cap is one line in TIER_CAPS, never a column or a matrix; gating stays a no-op while switched off). Cut the citation warning and the "Before #1388 this rule claimed..." story; project-rules.md §18 already holds the detail. | [P9] "Long explanations or tutorials" |
| CLAUDE.md:58 | "State-changing AJAX uses `validateCsrfRequest()` (same-origin), not a baked session token" | KEEP | Rule 29. Security convention. | no source |
| CLAUDE.md:60 | "An SPA fragment can NEVER carry an executable inline `<script>`" | KEEP | Rule 30. Explains a silent failure and the one correct pattern. History and a dated correction note are under half of it. | no source |
| CLAUDE.md:62 | "Same-origin requests use `apiFetch()` / `apiFetchJson()`" | KEEP | Rule 31. Code convention with its reason. | no source |
| CLAUDE.md:64 | "must tear itself down on EVERY navigation, as its first statement, before any early return" | KEEP | Rule 32. Code convention. | no source |
| CLAUDE.md:66 | "A URL parameter another page emits is a contract — honour it or stop emitting it" | KEEP | Rule 33. The three incidents are close to half, but they show the mistake; the "grep who links to it first" step is the instruction. | no source |
| CLAUDE.md:68 | "A guard must be mutation-tested, and derived from the tree rather than from a list you typed" | REWRITE | Rule 34. The rule (build the list from the code, break the thing and watch the test fail, keep the test narrow) is good evidence practice. Most of the words are a list of past wrong-but-green tests. Keep the rule, cut the list. | [P9] "Long explanations or tutorials" |
| CLAUDE.md:70 | "Cross-file agreement needs a mechanism, not a comment" | KEEP | Rule 35. Mostly substance. | no source |
| CLAUDE.md:72 | "Bootstrap CDN tags come from `includes/bootstrap_assets.php` — never typed into a page" | KEEP | Rule 36. About half is the story of why pages copied the tags, but the lesson drawn from it ("check whether the shared module is too big to adopt") is useful. | no source |
| CLAUDE.md:74 | "Songbook publisher is a REGISTRY" | KEEP | Rule 37. Code convention. | no source |
| CLAUDE.md:76 | "QR codes are generated by the CueRCode service, server-side" | KEEP | Rule 38. Code and security convention. | no source |
| CLAUDE.md:78 | "Song/set-list printing renders through ONE renderer" | KEEP | Rule 39. Code convention. | no source |
| CLAUDE.md:80 | "the mint response is the truth, not the request" | KEEP | Rule 40. Code convention. | no source |
| CLAUDE.md:82 | "a migration must NEVER hardcode `/public_html/` in an include path" | KEEP | Rule 41. A real trap with a guard. Note: project-rules.md §16.1 still teaches the older method this rule replaces (see that row). | no source |
| CLAUDE.md:84 | "Organisation logos — SVG uploads go through the ONE dedicated hardened sanitiser" | REWRITE | Rule 42. About 7,800 characters, longer than many whole instruction files. Keep the never-do list (one sanitiser, <img src> only, never read ContentOriginal, kinds come from the one map, screens use the one themed resolver) and the guard names. Move the design walk-through to the plan files it already cites. | [P9] "Long explanations or tutorials" |
| CLAUDE.md:86 | "Per-line song data is kept with a line by its IDENTITY, never by its position" | REWRITE | Rule 51. The most important rule here, so keep it. But the two dated "Corrected 2026-09-08" notes and the three-incident story are about half of it; cut those. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:92 | "A duplicate `<nav>` on an admin page." | DELETE | Red flag that only restates checkpoint 1 and line 17. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:93 | "A duplicate `<link rel="stylesheet" href="/css/app.css">`" | DELETE | Red flag that only restates line 17 ("MUST NOT inline ... admin-css loads"). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:94 | "A hard-coded list of roles, entitlements, licence types, tier names, or card IDs that already exists in a central map." | KEEP | Not a pure restatement: it is the general form, and roles and card IDs are not covered by any numbered rule. | no source |
| CLAUDE.md:95 | "A PDO / mysqli instantiation outside `getDbMysqli()`." | DELETE | Red flag that only restates checkpoint 5. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:96 | "A `<script>` loading Bootstrap or Bootstrap-Icons on a page that also includes `admin-footer.php` (double-load)." | DELETE | Red flag that only restates checkpoint 2. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:97 | "An inline click handler that re-implements behaviour the corresponding shared JS module already offers." | DELETE | Red flag that only restates the modularity rule (line 13). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:98 | "A hand-rolled regex / `URL.hostname.endsWith(...)` ladder" | DELETE | Red flag that only restates checkpoint 11. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:99 | "A new admin list page that doesn't opt into `.admin-table-responsive` + sortable headers" | KEEP | Adds something checkpoint 13 lacks: opting in is required when the neighbouring pages do. | no source |
| CLAUDE.md:100 | "A duplicate songbook/song/credit-person/work "external links" editor" | DELETE | Red flag that only restates checkpoint 15. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:101 | "A second copy of the duplicate/counterpart similarity maths" | DELETE | Red flag that only restates rule 22 (including the absorbed review page). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:102 | "A new admin page that hardcodes `<html lang="en" data-bs-theme="dark">`." | DELETE | Red flag that only restates checkpoint 16. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:103 | "A read-side endpoint that materialises the WHOLE corpus" | DELETE | Red flag that only restates rule 17. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:104 | "A prose link styled `text-decoration: underline; color: blue;`" | DELETE | Red flag that only restates rule 18, and its wording ("handles muted styling") describes the older style that rule 18 says was reversed. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:105 | "A new `migrate-*.php` script that creates a table or adds a column but doesn't update `appWeb/.sql/schema.sql` in the same commit." | DELETE | Red flag that only restates rule 19. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:106 | "A new entry in `$migrationOrder` (in `manage/setup-database.php`) without a matching probe in `$migrationProbes`" | DELETE | Red flag that only restates rule 19, and describes the old hand-edited lists that rule 19 says are now generated from one registry entry. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:107 | "An `ENUM` column for any vocabulary that could grow" | KEEP | Mostly restates rule 20, but adds one thing it lacks: existing ENUMs are grandfathered, so Claude should not "fix" them. | no source |
| CLAUDE.md:108 | "Per-line translations or annotations stored as a JSON parallel array on `tblSongComponents`" | DELETE | Red flag that only restates rule 21. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:109 | "An incremental `ALTER` that re-opens a schema family already shipped one-pass" | DELETE | Red flag that only restates rule 20. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:110 | "A songbook list / grid that filters out `IsOfficial = 0` books" | DELETE | Red flag that only restates rule 24. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:111 | "Renaming `tblCatalogues` / the `/manage/catalogues` route" | DELETE | Red flag that only restates rule 24. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:112 | "A hard-coded theme / tag list, or re-seeding theme vocabulary ad-hoc" | DELETE | Red flag that only restates rule 23. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:113 | "A page or handler that treats `$db->query(...)` / `$db->prepare(...)` as returning" | KEEP | No numbered rule covers this (database errors throw, so a "returned false" check is dead code). Unique. | no source |
| CLAUDE.md:114 | "A second lyric-line reader or writer" | DELETE | Red flag that only restates rule 25 and its guard. Its clause about never auto-running the destructive drop is listed under "For you to decide". | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:115 | "A re-forked tier→capability matrix" | DELETE | Red flag that only restates rule 28. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:116 | "A state-changing AJAX endpoint that compares a per-render baked `$_SESSION['csrf_token']`" | DELETE | Red flag that only restates rule 29. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:117 | "An executable inline `<script>` in an `includes/pages/*.php` or `includes/partials/*.php` fragment" | DELETE | Red flag that only restates rule 30. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:118 | "A raw `ihymns:*` / `iHymns:*` DOM event-name literal outside `js/constants.js`" | KEEP | No numbered rule covers event names (rule 35 only mentions them in passing). Unique. | no source |
| CLAUDE.md:119 | "Third-party code inside an authenticated admin session must be pinned exactly, SRI-checked" | KEEP | Covers every third-party library, not only Bootstrap (rule 36). Unique. | no source |
| CLAUDE.md:120 | "An admin page whose own gate differs from the entitlement its nav entry advertises" | KEEP | Not covered by a numbered rule. Unique. | no source |
| CLAUDE.md:121 | "Use a root-absolute path" | KEEP | Relative fetch() paths: not covered by a numbered rule. Unique. | no source |
| CLAUDE.md:122 | "A new `window.fetch` override" | DELETE | Red flag that only restates rule 31. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:123 | "A change to what a route SERVES" | DELETE | Red flag that only restates rule 33. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:124 | "A new CI guard that hardcodes the list of things it checks" | DELETE | Red flag that only restates rule 34. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:125 | "A client that distinguishes API failure KINDS by regex-matching the server's error sentence" | DELETE | Red flag that only restates rule 35. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:126 | "A hardcoded CDN `<script>`/`<link>` for Bootstrap or Bootstrap-Icons anywhere" | DELETE | Red flag that only restates rule 36. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:127 | "A fixed/sticky global UI element" | DELETE | Red flag that only restates rule 32. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:128 | "A re-forked publisher CRUD" | DELETE | Red flag that only restates rule 37. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:130 | "A client-side QR-code library" | DELETE | Red flag that only restates rule 38. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:132 | "must all flow through the ONE body renderer" | DELETE | Red flag that only restates rule 39. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:133 | "A set-list share write" | DELETE | Red flag that only restates rule 40. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:135 | "A migration under `appWeb/.sql/` with an" | DELETE | Red flag that only restates rule 41. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:136 | "An organisation-logo SVG that reaches storage without going through `ihymnsSanitizeSvg()`" | DELETE | Red flag that only restates rule 42. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:137 | "A themed screen surface (header/projector/OG-card) that forks its own kind/variant fallback logic" | DELETE | Red flag that only restates rule 42 (its #1840 part). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:139 | "NEVER a free-text box into a registry" | KEEP | Rule 43. Code convention. | no source |
| CLAUDE.md:141 | "Forms collect only what's needed — DERIVE or OMIT everything else" | KEEP | Rule 44. Code convention. | no source |
| CLAUDE.md:143 | "`Label` is DISPLAY-ONLY and `Type` stays authoritative" | KEEP | Rule 45. Long, but mostly the gotchas Claude needs. | no source |
| CLAUDE.md:145 | "Versioning is TAG-FREE, Conventional-Commit-driven" | REWRITE | Rule 46. About 7,000 characters. The parts Claude acts on are short: start pull request titles with a Conventional-Commit label, add "Release: patch" only for a deliberate bug-fix release, add a WHATS-NEW.md bullet for user-visible features, never add git tags. The pipeline mechanics are a tutorial that belongs in the deploy docs. | [P9] "Long explanations or tutorials" |
| CLAUDE.md:147 | "ProPresenter 7 chords are POSITIONED attributes over clean text" | KEEP | Rule 47. Code convention. | no source |
| CLAUDE.md:149 | "Every capability is reachable through the API" | KEEP | Rule 48. Code and security convention. (Its "re-check" wording is about checking which organisation owns a row, not a verify-twice rule.) | no source |
| CLAUDE.md:151 | "Guided admin wizards ride ONE shared stepper" | KEEP | Rule 49. Code and security convention. | no source |
| CLAUDE.md:153 | "Per-channel search-engine visibility is ONE shared core keyed off `ihymns_environment()`" | KEEP | Rule 50. Code convention. | no source |
| CLAUDE.md:155 | "Per-line data carried across an edit by POSITION rather than by `tblLyricLines.Id`" | DELETE | Red flag that only restates rule 51; half of it is a dated correction note. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:157 | "When in doubt: extract first, use second." | DELETE | Restates the modularity rule at line 13. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:162-168 | "Web / PWA (PHP + vanilla JS modules + Bootstrap 5)" | DELETE | A folder map that a directory listing gives. It is also incomplete (the .claude/ line leaves out most of that folder). | [P9] "Anything Claude can figure out by reading code" |
| CLAUDE.md:174 | "Commits have descriptive first-line summaries; wrapped body explaining the WHY, not just the WHAT." | KEEP | House style for commits. | no source |
| CLAUDE.md:175 | "Every user-reported bug or feature gets a tracking GitHub issue" | KEEP | Process rule. | no source |
| CLAUDE.md:180 | "Audit before opening a PR: PHP syntax" | KEEP | Runs real checks (syntax, security, accessibility). That is evidence, not verify-twice. | [P9] "Have Claude show evidence rather than asserting success" |
| CLAUDE.md:184-185 | "Never surface a bare question." | KEEP | Sets the shape of every question to you: decision, why, options, recommendation, what you need back. | [P5a] "say plainly what it did, what it found, and what it needs from you." |
| CLAUDE.md:187 | "what is actually being chosen between, in one sentence, in plain language." | KEEP | Part of the question shape. | no source |
| CLAUDE.md:188-189 | "what makes it a judgement call rather than something to work out from the code." | KEEP | Part of the question shape. | no source |
| CLAUDE.md:190-191 | "each with its real consequence, including the cost of doing nothing." | KEEP | Part of the question shape. | no source |
| CLAUDE.md:192-193 | "say which one you would pick" | KEEP | Part of the question shape. | no source |
| CLAUDE.md:194-195 | "the smallest possible reply that unblocks you" | KEEP | Part of the question shape. | no source |
| CLAUDE.md:197-198 | "Also state plainly whether the decision" | KEEP | Lets you defer without holding anything up. | no source |
| CLAUDE.md:200-201 | "pick the defensible default, say so explicitly, and flag it as trivially changeable" | KEEP | Matches the guidance on making routine calls yourself. | [P6] "Make routine judgment calls yourself, and check in only when different readings of the request would lead to materially different work." |
| CLAUDE.md:203-206 | "if further investigation undermines your own recommendation, say so unprompted" | KEEP | Truth rule (correct yourself when the facts change). Always kept. | no source |
| CLAUDE.md:210 | "After every substantive piece of work — and always before a session ends — run the full" | REWRITE | standing-tasks.md:199-201 says only items 1–2 after each piece of work, and the full list before a pull request and at session end. This line says the full list after every piece. Make them say the same (the 199-201 version). | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| CLAUDE.md:212 | "model routing (think hard + workflows; sequential" | DELETE | A summary of standing-directives.md that line 229 repeats. It restates the review-until-clean loop and the full review on return (both flagged in that file) without adding anything. One pointer is enough. Flag: repeats two verify-twice rules. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:214 | "inline line-by-line annotations in two registers" | REWRITE | standing-tasks.md:23 limits this to "non-obvious logic" and :195-196 says "Don't narrate the obvious". This line drops that limit, so the two disagree. Add "on non-obvious logic only". ("ELI5" is also jargon by your own plain-English rule.) | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| CLAUDE.md:215 | "Every identified task gets an issue, filed at the moment of discovery" | KEEP | Process rule; includes the truth rule "verify before filing". | no source |
| CLAUDE.md:216 | "keep status accurate." | KEEP | Milestones and Project board. Process rule. | no source |
| CLAUDE.md:217 | "(`iHymns.wiki/`) — update affected pages" | REWRITE | Wrong folder. iHymns.wiki/ does not exist; the wiki is the wiki/ folder inside this repo (19 tracked files). .OpenAI/CONTEXT.md:18 has it right. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| CLAUDE.md:218 | "every user-visible push/PR adds a bullet under the current" | KEEP | The What's New duty. Matches the deploy, which reads WHATS-NEW.md. | no source |
| CLAUDE.md:219 | "Memory (auto-memory + `MEMORY.md`), Context (`ProjectBrief.md` + this file), History" | KEEP | Process rule. | no source |
| CLAUDE.md:220 | "so Codex never works from an older picture than Claude" | KEEP | Process rule. | no source |
| CLAUDE.md:222 | "never silently skip, never claim it was done." | KEEP | Truth rule. Always kept. | [P7] "Only if no real check can run here, say which one you did not run and why instead of reporting the change as done." |
| CLAUDE.md:226 | "current project state, versions, phase, database schema summary." | KEEP | Pointer. | no source |
| CLAUDE.md:227 | "original multi-platform scoping." | KEEP | Pointer. | no source |
| CLAUDE.md:228 | "this file's detailed expansion" | KEEP | Pointer. | no source |
| CLAUDE.md:229 | "owner standing operating directives" | REWRITE | The pointer is useful; the long summary of the other file is not, and it repeats line 212. Cut to "standing-directives.md — how the work is run; read it at session start". | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| CLAUDE.md:233 | "The repo carries **scrubbed copies** in `.claude/sessions/`" | REWRITE | Out of date: sessions/README.md:17 says raw logs have not been committed since 2026-09-07 (#2096), and the sync script now works both ways. Point at sessions/README.md instead. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| CLAUDE.md:243 | "it's not project policy." | REWRITE | Out of date: the repo now installs computer-wide rules into ~/.claude/CLAUDE.md from device-level-rules.md (standing-directives.md:250-252). Keep the second sentence (copy lasting guidance into project-rules.md). | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |

### AGENTS.md

`AGENTS.md` (repo root), original version — 75 lines. 0 DELETE, 4 REWRITE, 19 KEEP, 1 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| AGENTS.md:5-6 | "If you are Claude Code, read `.claude/CLAUDE.md` as well — it is the fuller document and it governs." | KEEP | Says which file wins if the two differ. | no source |
| AGENTS.md:10-13 | "Write the way you would explain something to a capable colleague who does not work on this particular system." | KEEP | Codex's copy of the plain-English rule. | no source |
| AGENTS.md:17-20 | "Use ordinary words. Say "a number that only ever counts upward and never resets"" | KEEP | Concrete before/after examples, the form that works best. | [P6] "Positive examples of the communication style you want tend to be more effective than instructions about what not to do." |
| AGENTS.md:21-23 | "When a technical term is genuinely needed" | KEEP | Plain-English rule. | no source |
| AGENTS.md:24-25 | "Using more words is fine, and better, if it makes the meaning clearer." | REWRITE | Same balance problem as CLAUDE.md:9. Say "explain what the reader needs, and cut what they do not". | [P4] "Prompt explicitly for conciseness instead." |
| AGENTS.md:26 | "Prefer short sentences. Break a long one into two." | KEEP | Plain-English rule. | no source |
| AGENTS.md:27-29 | "Explain the "why", not just the "what"." | KEEP | Plain-English rule. | no source |
| AGENTS.md:30 | "Avoid unexplained abbreviations and internal shorthand on first use." | KEEP | Plain-English rule. | no source |
| AGENTS.md:31 | "Avoid filler that sounds impressive and says nothing." | KEEP | Plain-English rule. | no source |
| AGENTS.md:33-34 | "This does not lower the standard of the work." | KEEP | Stops plain wording being read as permission to simplify the work. | no source |
| AGENTS.md:36-38 | "be direct about what is finished, what is not, what was not checked, and what went wrong." | KEEP | Truth rule. Always kept. | [P7] "Only if no real check can run here, say which one you did not run and why instead of reporting the change as done." |
| AGENTS.md:42-43 | "They apply to every AI tool working here, not only Claude." | KEEP | Scope. | no source |
| AGENTS.md:45-46 | "opens with a "Current state" section. Read it first." | KEEP | Where to start. | no source |
| AGENTS.md:47-48 | "Read `.OpenAI/CONTEXT.md` and `.OpenAI/MEMORY.md` at the start." | KEEP | Where to start. | no source |
| AGENTS.md:51-52 | "commit and push, update that task's GitHub issue" | KEEP | Per-task close-out. | no source |
| AGENTS.md:53-54 | "Fix what the review finds and review again, until a review finds nothing (§13)." | REWRITE | Flag: verify-twice. You asked for cross-tool review, and the guidance supports reviews the user asked for. But "until a review finds nothing" has no end point, because a reviewer can always find something. Have the reviewer report everything, then stop when no remaining finding affects correctness or what was asked. (Note the tension: [P6] says report everything; this [P9] line is about when to stop, not what to report.) | [P9] "Tell the reviewer to flag only gaps that affect correctness or the stated requirements, and treat the rest as optional." |
| AGENTS.md:55-56 | "another suitable tool may take over, provided the handoff is up to date." | KEEP | Fallback rule. | no source |
| AGENTS.md:56-57 | "Switch back to the main tool (Claude Code) as soon as it returns, and have it do a full review of the fallback work." | REWRITE | Flag: verify-twice. Switching back is fine. The "full review" is a second review of work the §13 loop already reviewed, and nothing says what it adds. Say what it checks that §13 did not, or drop it. (The quote is the closest line; it is about the model re-checking itself, and here another tool does the re-check, so the fit is not exact.) | [P6] "Avoid instructing re-checks it already performs ("double-check your answer," "re-verify before responding")" |
| AGENTS.md:57-58 | "Record every switch in the handoff (§14)." | KEEP | Keeps a record. | no source |
| AGENTS.md:59 | "of the queued tasks and the state of each one (§12)." | KEEP | Your chosen update style; the guidance says the model follows instructions like this. | [P5b] "if you want more frequent or predictable updates, such as a one-line statement of intent before the first tool call and a short recap at the end, say so in the system prompt; the model is responsive to such instructions." |
| AGENTS.md:60-61 | "when the owner has to make the decision. Ask everything you can foresee at the start, in one go" | REWRITE | Good autonomy rule, but it leaves out the one stop the guidance always wants: before a risky or destructive step. Add that clause. Which steps count is your call (see "For you to decide"). | [P7] "Keep working until everything the user asked for is done, and only stop to ask when you can't go on without the user or before a risky step." |
| AGENTS.md:65-67 | "Read `.claude/CLAUDE.md`. It contains fifty-plus numbered rules" | KEEP | Not a repeat of lines 5-6, which speak only to Claude Code. It is the only line in AGENTS.md (the file Codex reads automatically) that tells Codex to read CLAUDE.md before changing code. (The first-pass inventory said DELETE; I disagree.) | no source |
| AGENTS.md:69-75 | "Per-line song data is kept with a line by its identity number, never by its position." | KEEP | Codex's plain-words copy of the most important rule (CLAUDE.md rule 51). | no source |

### standing-tasks.md

`.claude/standing-tasks.md`, original version — 205 lines. 1 DELETE, 6 REWRITE, 40 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| standing-tasks.md:3-8 | "These tasks run **after every substantive piece of work** — ideally before a commit is considered "done"" | REWRITE | Lines 199-201 of the same file say only items 1–2 after each piece of work, and the full list before a pull request and at session end. Pick one; 199-201 is the workable one. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| standing-tasks.md:17-18 | "Run this whole list after any feature, fix, refactor, audit, or design decision." | REWRITE | Same contradiction with lines 199-201. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| standing-tasks.md:21-28 | "Annotate to the project standard" | KEEP | Limited to non-obvious logic, which matches the standard at lines 183-196. | no source |
| standing-tasks.md:28-29 | "never mass-rewrite comments across the whole tree in one unreviewed sweep." | KEEP | Holds scope. | no source |
| standing-tasks.md:32-33 | "Every user-reported bug / feature / decision has a tracking issue" | KEEP | Process rule. | no source |
| standing-tasks.md:37-39 | "File the issue at the moment of discovery, not at the end of the work." | KEEP | Process rule. | no source |
| standing-tasks.md:41-44 | "one per actionable item, each with the `file:line` evidence that established it." | KEEP | Evidence rule. | [P9] "Have Claude show evidence rather than asserting success" |
| standing-tasks.md:45-49 | "Group related findings under an Epic" | KEEP | Process rule. | no source |
| standing-tasks.md:50-53 | "Work already done without an issue → file it retrospectively." | KEEP | Process rule. | no source |
| standing-tasks.md:54-56 | "A verification task is still a task." | KEEP | Process rule. | no source |
| standing-tasks.md:57-60 | "Do not file an issue per symptom when one cause explains them all" | KEEP | Truth rule ("verify first" = check the facts before filing). Always kept. | no source |
| standing-tasks.md:61 | "Unactioned suggestions and owner decisions → `for consideration`." | KEEP | Process rule. | no source |
| standing-tasks.md:62-64 | "Update issues to reflect reality" | KEEP | Process rule. | no source |
| standing-tasks.md:65 | "Unactioned suggestions → `for consideration`-labelled issues (global rule)." | DELETE | Repeats line 61, four lines up. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-tasks.md:66-67 | "Keep schema/state issues accurate at all times" | KEEP | Process rule. | no source |
| standing-tasks.md:71-85 | "An issue sweep structurally cannot find an unfiled bug." | KEEP | Tells Claude to say which kind of sweep it is running. | no source |
| standing-tasks.md:89-101 | "Every bug that survived the sweeps shares one shape" | REWRITE | The point (silent no-ops look finished, so a text search cannot find them) helps audits. Most of the words are four past incidents. Keep one example. | [P9] "Long explanations or tutorials" |
| standing-tasks.md:105-106 | "Run these as separate passes with separate agents" | KEEP | A deliberate split for audits: each pass asks a different question. Not a verify-twice rule. | no source |
| standing-tasks.md:108-123 | "Write-path integrity." | KEEP | The five audit lenses (grouped). | no source |
| standing-tasks.md:127-128 | "Evidence or it didn't happen." | KEEP | Truth rule. Always kept. | [P9] "Have Claude show evidence rather than asserting success" |
| standing-tasks.md:129-131 | "Exclude what this branch already fixed" | KEEP | Avoids re-reporting known fixes. | no source |
| standing-tasks.md:132 | "Ban style findings." | REWRITE | Flag: severity filter. Applied while looking, it means style problems are never reported at all. You want everything reported so you can filter: report style findings in a separate low-priority list instead. | [P6] "ask it to report everything and filter in a separate pass instead." |
| standing-tasks.md:133 | "Rank by user impact" | KEEP | Checked: this ranks, it does not filter. Everything is still reported, just in order. | no source |
| standing-tasks.md:134-135 | "A short honest list beats a long padded one." | KEEP | Truth rule (do not pad with weak or made-up findings), so kept. Flag: it could be read as "report less"; if you want, add "but report every real finding, however small". | no source |
| standing-tasks.md:138-140 | "Assign new issues to the right Milestone" | KEEP | Process rule. | no source |
| standing-tasks.md:143-145 | "Update the affected Wiki page(s)" | KEEP | Process rule. (Its heading, line 142, names iHymns.wiki/; see the line 146 row.) | no source |
| standing-tasks.md:146 | "The Wiki is a separate nested clone — commit + push it on its own." | REWRITE | Wrong: the wiki is the wiki/ folder inside this repo (19 tracked files) and iHymns.wiki/ does not exist. .OpenAI/CONTEXT.md:18 says so. Say "wiki/ is part of this repo; commit it with the change". | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| standing-tasks.md:149-151 | "`README.md` (version badge, features, structure, links)" | KEEP | Process rule. The files named exist. | no source |
| standing-tasks.md:152-161 | "Every push/PR that adds or changes something a *user* can see or do MUST add" | KEEP | The What's New duty. | no source |
| standing-tasks.md:162-164 | "`SECURITY.md` (disclosure policy + security model)" | KEEP | Process rule. | no source |
| standing-tasks.md:167-168 | "per-user auto-memory" | KEEP | Process rule. | no source |
| standing-tasks.md:169-170 | "add a dated continuation note; codify any new convention." | REWRITE | Dated notes stack up, and they are a big part of why CLAUDE.md is 123 KB. Codify the convention by editing the rule in place; git keeps the dates. | [P8] "target under 200 lines per CLAUDE.md file. Longer files consume more context and reduce adherence." |
| standing-tasks.md:171-172 | "what landed (commit SHAs)," | KEEP | Handoff contents. | no source |
| standing-tasks.md:173 | "`.claude/project-rules.md` for permanent expansions of conventions." | KEEP | Says where long-form rules go. | no source |
| standing-tasks.md:176 | "the same kind of facts as `.claude/MEMORY.md`, written for Codex." | KEEP | Codex notes. | no source |
| standing-tasks.md:177 | "`.OpenAI/CONTEXT.md` (and `AGENTS.md` at the repo root if a rule changed)." | KEEP | Codex notes. | no source |
| standing-tasks.md:178-179 | "do NOT copy the handoff here." | KEEP | Prevents two handoffs drifting apart. | no source |
| standing-tasks.md:183-184 | "write for a future maintainer who is smart but new to this file." | KEEP | Annotation standard. | no source |
| standing-tasks.md:186-187 | "what this file/class does, where it sits in the architecture" | KEEP | Annotation standard. | no source |
| standing-tasks.md:188-191 | "where logic isn't self-evident:" | KEEP | Annotation standard ("ELI5" is jargon, but the meaning is clear in context). | no source |
| standing-tasks.md:192-193 | "when they help: MDN (web APIs/CSS/JS), the PHP manual," | KEEP | Annotation standard. | no source |
| standing-tasks.md:194 | "comment density and idiom should fit the file." | KEEP | Annotation standard. | no source |
| standing-tasks.md:195-196 | "Over-commenting is as harmful as under-commenting." | KEEP | Annotation standard; holds scope. | no source |
| standing-tasks.md:199 | "items 1–2 minimum before a commit is "done"." | KEEP | Make this the one statement of when the checklist runs. | no source |
| standing-tasks.md:200 | "the full list, plus the pre-PR security review." | KEEP | When the checklist runs. | no source |
| standing-tasks.md:201 | "the full list, so the next session resumes from truth." | KEEP | When the checklist runs. | no source |
| standing-tasks.md:203-205 | "never silently skip and never claim it was done." | KEEP | Truth rule. Always kept. | [P7] "Only if no real check can run here, say which one you did not run and why instead of reporting the change as done." |

### device-level-rules.md

`.claude/device-level-rules.md`, original version — 54 lines. 0 DELETE, 4 REWRITE, 7 KEEP, 0 for you to decide.

Lines 3-22 are notes for people about installing the block; they are not installed, so they have no rows. Lines 25-53 are installed into `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` and apply to every project on the computer.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| device-level-rules.md:28-30 | "Explain things in plain, everyday English" | KEEP | The only copy of the plain-English rule that your other projects see. | no source |
| device-level-rules.md:30-31 | "More words are fine if they make the meaning clearer." | REWRITE | Same balance problem as CLAUDE.md:9, and this copy applies to every project on the computer. | [P4] "Prompt explicitly for conciseness instead." |
| device-level-rules.md:34 | "AI coding tool or its agents, whichever tools are in use." | KEEP | Scope. | no source |
| device-level-rules.md:36-38 | "another suitable AI tool may take over," | KEEP | Fallback rule. | no source |
| device-level-rules.md:39-41 | "being kept up to the minute" | REWRITE | This copy is installed for every project on the computer, and most projects have no handoff document. Say "if the project keeps one". | no source — owner preference/fact fix: the rule assumes a handoff file that most projects do not have. |
| device-level-rules.md:42-43 | "as soon as it is available again." | KEEP | Fallback rule. | no source |
| device-level-rules.md:44-45 | "When the main tool is back, have it do a FULL review" | REWRITE | Flag: verify-twice. Same as AGENTS.md:56-57, but here it applies to every project: a second review on top of the cross-tool review, with nothing saying what it adds. (Closest quote; not an exact fit, as above.) | [P6] "Avoid instructing re-checks it already performs ("double-check your answer," "re-verify before responding")" |
| device-level-rules.md:46-47 | "which tool took over, when, what it did" | KEEP | Keeps a record. | no source |
| device-level-rules.md:50-51 | "AI tool review the work from the one that wrote it" | KEEP | Cross-tool review; you asked for it. | [P7] "don't launch reviewer sub-agents unless the user asked for a review" |
| device-level-rules.md:51-52 | "Fix what the review finds and review again, until a review finds nothing." | REWRITE | Flag: verify-twice. No end point, and installed computer-wide. Same fix as standing-directives.md:271. | [P9] "Tell the reviewer to flag only gaps that affect correctness or the stated requirements, and treat the rest as optional." |
| device-level-rules.md:52-53 | "An empty review output is not a clean review. Check the tool actually ran." | KEEP | Truth rule. Always kept. | no source |

### standing-directives.md

`.claude/standing-directives.md` — 306 lines. 10 DELETE, 11 REWRITE, 51 KEEP, 5 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| standing-directives.md:3-4 | "these apply to every AI tool working on this repo" | KEEP | Scope. | no source |
| standing-directives.md:13-15 | "deep analysis and planning now use **Opus**, not Fable." | DELETE | History. The bullets below already say Opus; nothing changes if the "why we switched" note goes. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:17-18 | "Pick the model to suit the work." | KEEP | Model choice. | no source |
| standing-directives.md:20-22 | "Think hard before acting." | REWRITE | Flag: a "think more" rule (the opposite of "don't overthink"). On Opus 5.5 thinking is always on and the effort setting is the main control, so say "use high effort (or the ultrathink keyword) for deep analysis and planning". Not a delete: the guidance also says a general "think thoroughly" beats a hand-written step plan, and the [P5b] removal advice is aimed at chat apps. | [P5b] "The model decides for itself how much to think, and effort is the main control." |
| standing-directives.md:22-24 | "(the Workflow tool, several agents driven by one script) to help plan and do the work where that helps." | KEEP | "Where that helps" already limits it. Addition 4 below covers when helpers are not worth it. | no source |
| standing-directives.md:25-27 | "Deep analysis and deep planning → Opus agents, one after another" | KEEP | Matches your 2026-09-23 decision. The deep-architect agent file still says Fable (see its row). | no source |
| standing-directives.md:28-29 | "Implementation → Sonnet or Haiku" | KEEP | Model choice. | no source |
| standing-directives.md:30 | "Complex implementation → Opus." | KEEP | Model choice. | no source |
| standing-directives.md:31-32 | "Check it, prove the tests can actually fail (rule #34), and never call something "done" without checking it." | KEEP | Truth and evidence rule: run a real check. Not verify-twice. | [P9] "Have Claude show evidence rather than asserting success" |
| standing-directives.md:45-46 | "is any branch that is not one of the long‑lived channel branches:" | KEEP | Definition used by the branch rules (which are under "For you to decide"). | no source |
| standing-directives.md:54-60 | "Check before assuming, because the answer changes as branches are merged and deleted:" | KEEP | A real check against the remote, not a remembered fact. | [P9] "Have Claude show evidence rather than asserting success" |
| standing-directives.md:64-67 | "The `archive/` exclusion is load‑bearing" | REWRITE | Keep one line ("archive/* is a parked copy, not work") so nobody drops it from the filter; cut the story. | [P9] "Long explanations or tutorials" |
| standing-directives.md:67-68 | "Run the command and read its output before acting on it" | KEEP | Truth rule. Always kept. | [P4] "Never speculate about code you have not opened." |
| standing-directives.md:70-76 | "Do not hard‑code the current branch name into this file." | REWRITE | A note for whoever edits this file. One sentence is enough; the story of the deleted branch can go. | [P9] "Long explanations or tutorials" |
| standing-directives.md:82-84 | "Keep each commit small, clearly described, and signed with the footer." | KEEP | Per-task close-out. (Its "create one first" clause is under "For you to decide".) | no source |
| standing-directives.md:85-87 | "Update its GitHub issue(s), one at a time, for each task." | KEEP | Per-task close-out. | no source |
| standing-directives.md:88-89 | "`CLAUDE.md`) so they match reality." | KEEP | "Match reality" already means "change only what is now untrue". | no source |
| standing-directives.md:90-94 | "Do not copy the handoff into `.OpenAI/`." | KEEP | Per-task close-out. | no source |
| standing-directives.md:95 | "Update the handoff document" | KEEP | Per-task close-out. | no source |
| standing-directives.md:99-102 | "continuously as work progresses" | KEEP | Keeps the handoff usable after an interruption. | no source |
| standing-directives.md:104-107 | "Update it **up to the minute**, not in batches." | KEEP | Also says to record which AI tool is working and to open with "Current state". | no source |
| standing-directives.md:111-112 | "Do **not** halt or pause unless you need an **explicit decision/approval** that only the owner can give." | REWRITE | Says when to stop, but not that a risky or destructive step always needs a stop; the guidance says autonomy never overrides that. Add the clause. Which steps count is under "For you to decide". | [P5b] "This does not override the need for confirmation on risky or destructive actions." |
| standing-directives.md:114-116 | "while that one item waits; never idle the whole queue on a single decision." | KEEP | Autonomy rule. | [P7] "Keep working until everything the user asked for is done, and only stop to ask when you can't go on without the user or before a risky step." |
| standing-directives.md:117-118 | "Prefer a defensible default + flag it" | KEEP | Matches the guidance on routine calls. | [P6] "Make routine judgment calls yourself, and check in only when different readings of the request would lead to materially different work." |
| standing-directives.md:124-127 | "so do not add a second one" | KEEP | A fact that prevents duplicate work. | no source |
| standing-directives.md:128-129 | "`README.md`, `CHANGELOG.md`, `DEV_NOTES.md`, `PROJECT_STATUS.md`," | KEEP | Docs duty. | no source |
| standing-directives.md:130 | "the user‑facing help content" | KEEP | Docs duty. | no source |
| standing-directives.md:131-133 | "If the project has web components and no browsable Swagger UI is bundled, add one" | REWRITE | Keep "keep api-docs.yaml accurate". Drop the "add a Swagger UI if none" clause: line 124 says one exists, so it can never apply here. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:134 | "Memory, Context, and this directory." | DELETE | Repeats §3 step 3 (line 88). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:135 | "never infer state from commit titles or other docs." | KEEP | Truth rule. Always kept. | [P4] "Never speculate about code you have not opened." |
| standing-directives.md:139-141 | "Verify before filing/closing." | KEEP | Truth rule (check facts against the code). Always kept. | [P4] "Never speculate about code you have not opened." |
| standing-directives.md:145-146 | "for the work itself, and for suggesting fixes, tweaks, improvements and new features." | KEEP | Suggesting is fine; the guidance only warns against building unrequested features, and says to mention ideas at the end. | [P7] "Don't add features, tests, files, docs or refactors that weren't asked for. If you think one would help, mention it at the end instead of doing it." |
| standing-directives.md:147-150 | "Use a different AI system to check the work." | DELETE | Flag: verify-twice (cross-tool review). The bullet itself says the loop lives in §13; this is a second statement of it. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:154-155 | "as long as correctness, the per‑task close‑out (§3), and the autonomy rules (§5) are honoured." | KEEP | Lets Claude order and bundle work. | no source |
| standing-directives.md:159-161 | "When a task needs clarification that only the owner can give, ask" | KEEP | Ask everything up front. Matches the guidance. | [P6] "performs best when given the complete task specification up front and left to run." |
| standing-directives.md:163-166 | "Front‑load the ambiguities." | DELETE | Repeats lines 159-161. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:167-168 | "Do not drip‑feed." | DELETE | Repeats lines 159-161. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:169-171 | "The narrow exception:" | DELETE | Repeats lines 117-118. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:173-174 | "This composes with §5 (autonomy)" | DELETE | Commentary; tells Claude nothing new. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:178-182 | "Update this file (don't fork it) if the owner amends any directive." | REWRITE | Keep this last sentence; drop the change log, which git already records. | [P9] "Information that changes frequently" |
| standing-directives.md:186-190 | "Write the way you would explain something to a capable colleague who does not work on this particular system." | KEEP | Make this the one full copy of the plain-English rule. | no source |
| standing-directives.md:192-196 | "this covers people who ARE technical too." | REWRITE | Keep "this covers technical readers too"; drop the five dates and "Asked for a FIFTH time". | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:198-200 | "Four asks means writing it down again is not the answer." | DELETE | Commentary on the rule's history, and itself one more copy. It also disagrees with line 192 on the count (four vs fifth). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| standing-directives.md:204-206 | "The pull is strongest under a long task list." | DELETE | Explains why jargon creeps in; gives Claude nothing to do. | [P9] "Long explanations or tutorials" |
| standing-directives.md:207-208 | "It slips most in progress reports and issue text" | KEEP | Tells Claude where to pay attention. | no source |
| standing-directives.md:209-211 | "Naming a thing is not explaining it." | KEEP | A concrete test. | no source |
| standing-directives.md:213-215 | "pick the two or three densest sentences and ask whether a capable colleague outside this project would follow them." | KEEP | Flag: a check before sending. Kept because it is narrow (two or three sentences, wording only) and, since you have had to ask five times, it is not a check Claude already does. | no source |
| standing-directives.md:219-221 | "Use ordinary words." | KEEP | Examples of the wording you want. | [P6] "Positive examples of the communication style you want tend to be more effective than instructions about what not to do." |
| standing-directives.md:222-223 | "When a technical term is genuinely needed" | KEEP | Plain-English rule. | no source |
| standing-directives.md:224-225 | "Using more words is fine, and better, if it makes the meaning clearer." | REWRITE | Same balance problem as CLAUDE.md:9. | [P4] "Prompt explicitly for conciseness instead." |
| standing-directives.md:226 | "Prefer short sentences. Break a long one into two." | KEEP | Plain-English rule. | no source |
| standing-directives.md:227 | "Explain the *why*, not just the *what*." | KEEP | Plain-English rule. | no source |
| standing-directives.md:228-229 | "Avoid unexplained abbreviations" | KEEP | Plain-English rule. | no source |
| standing-directives.md:231-232 | "This does not lower the standard of the work." | KEEP | Plain-English rule. | no source |
| standing-directives.md:234-236 | "be direct about what is finished, what is not, what was not checked, and what went wrong." | KEEP | Truth rule. Always kept. | [P7] "Only if no real check can run here, say which one you did not run and why instead of reporting the change as done." |
| standing-directives.md:238-248 | "keep all of these in step" | KEEP | Keeps the copies findable. (Six copies is a lot; the project-rules.md §22 rows delete one.) | no source |
| standing-directives.md:250-252 | "Edit the text there and re‑run the script" | KEEP | Stops hand edits to the computer-wide files. | no source |
| standing-directives.md:256-260 | "Send one when the work starts, whenever a task changes state, and at the end." | KEEP | Your chosen update style; the guidance says the model follows instructions like this. | [P5b] "if you want more frequent or predictable updates, such as a one-line statement of intent before the first tool call and a short recap at the end, say so in the system prompt; the model is responsive to such instructions." |
| standing-directives.md:264-266 | "All code goes through this loop before it counts as finished." | KEEP | Flag: review loop. Kept: you asked for a cross-tool review of every change, and the guidance only warns against reviewer helpers the user did not ask for. | [P7] "don't launch reviewer sub-agents unless the user asked for a review" |
| standing-directives.md:268 | "(for example `codex review`, or through the dev‑team plugins)." | KEEP | How to run the review. | no source |
| standing-directives.md:269-270 | "Where a finding is wrong, write down why instead of making the change." | KEEP | Stops blind fixes. | no source |
| standing-directives.md:271 | "Repeat steps 2 and 3 until a review finds **no issues**." | REWRITE | Flag: verify-twice. No end point: a reviewer can always find something. Have Codex report everything, and end the loop when no remaining finding affects correctness or what was asked. (Tension: [P6] says report everything; this [P9] line is about when to stop.) | [P9] "Tell the reviewer to flag only gaps that affect correctness or the stated requirements, and treat the rest as optional." |
| standing-directives.md:275-277 | "An empty review is not a clean review." | KEEP | Truth rule. Always kept. | no source |
| standing-directives.md:278-279 | "A fresh reviewer with no memory of how the code was built reviews it instead." | REWRITE | Flag: verify-twice. When Codex is out, this has Claude start a helper to review Claude's own work, which is what the guidance says not to do. Keep the "catch-up Codex review" issue so the real review still happens; drop the stand-in review. | [P6] "Do not delegate work you can finish yourself in a handful of tool calls, and do not use subagents to verify or double-check your own work." |
| standing-directives.md:282-283 | "The loop is in addition to this repo's own automated checks" | KEEP | Real checks (lint, tests, CI guards). Evidence, not verify-twice. | [P9] "Have Claude show evidence rather than asserting success" |
| standing-directives.md:287-289 | "AI tool or its agents — Claude Code, Codex, or anything else." | KEEP | Scope. | no source |
| standing-directives.md:290-293 | "you may hand the work to another suitable AI tool" | KEEP | Fallback rule. | no source |
| standing-directives.md:294-295 | "Do not stay on the fallback just because it is working." | KEEP | Fallback rule. | no source |
| standing-directives.md:296-298 | "When the main service is back, run a FULL review" | REWRITE | Flag: verify-twice. The text admits §13 already catches most differences; say what the full review adds, or drop it. (Closest quote; not an exact fit, as above.) | [P6] "Avoid instructing re-checks it already performs ("double-check your answer," "re-verify before responding")" |
| standing-directives.md:299-300 | "which tool took over, when, what it did" | KEEP | Keeps a record. | no source |
| standing-directives.md:301-302 | "is the usual reviewer and first fallback." | KEEP | Names the tools. | no source |
| standing-directives.md:304-306 | "so it applies in every project, not just this one." | DELETE | Repeats lines 250-252. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |

### project-rules.md

`.claude/project-rules.md` — 600 lines. 13 DELETE, 11 REWRITE, 60 KEEP, 4 for you to decide.

Behaviour rules have a row each. Pure code-convention sections are grouped, and the row says so.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| project-rules.md:7 | "See `.claude/CLAUDE.md` for the full policy." | KEEP | Pointer. | no source |
| project-rules.md:11-26 | "`$LICENCE_TYPES` in `manage/organisations.php` (migrating to `tblLicenceTypes`, #459)" | REWRITE | §1.1 lookup table (grouped). Useful, but one row is out of date: it says the licence list is "migrating", while §18.7 (line 415) of this same file says licence_registry.php is now the one reader. Fix that row. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:28-32 | "Keep the palette in one shared `Theme.swift`." | KEEP | §1.2 Apple (grouped code convention). | no source |
| project-rules.md:34-37 | "FireOS is a variant of the Android target" | KEEP | §1.3 Android (grouped code convention). | no source |
| project-rules.md:39-49 | "`tblCamelCase` tables, `CamelCase` columns" | KEEP | §2 naming (grouped code convention). | no source |
| project-rules.md:53 | "No role-string comparisons in business logic." | KEEP | §3 security convention. | no source |
| project-rules.md:54 | "every POST handler calls `validateCsrf()` before dispatch." | REWRITE | CLAUDE.md rule 29 and §18.6 of this file say state-changing AJAX must use validateCsrfRequest(), and that validateCsrf() alone on a long-lived page causes the random "CSRF error". Name validateCsrfRequest() for AJAX. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:55-59 | "uses prepared statements with placeholders." | KEEP | §3 items 3-7 (grouped security conventions). | no source |
| project-rules.md:61-66 | "System boundaries only." | KEEP | §4 errors (grouped code convention). | no source |
| project-rules.md:68-76 | "Colour is never the only signal of state" | KEEP | §5 accessibility (grouped code convention). | no source |
| project-rules.md:78-83 | "N+1 DB queries are a bug." | KEEP | §6 performance (grouped code convention). | no source |
| project-rules.md:87 | "`npm test` runs the song-parser harness at minimum." | DELETE | Wrong, and the code answers it: package.json:20 runs tools/run-node-tests.js. | [P9] "Anything Claude can figure out by reading code" |
| project-rules.md:88 | "`npm run test:php` + `npm run test:js` sweep syntax across the tree." | KEEP | True (package.json:21-22); tells Claude which checks to run. | no source |
| project-rules.md:89 | "Manual test plan lives in every PR description" | KEEP | Process rule. | no source |
| project-rules.md:93 | "Issue BEFORE commit when possible" | KEEP | Process rule. | no source |
| project-rules.md:94 | "Retrospective issues for work that shipped without one are OK" | DELETE | Repeats standing-tasks.md:50-53. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:95 | "Every PR description explains WHY the change exists" | KEEP | Process rule. | no source |
| project-rules.md:100 | "Don't invent SRI hashes." | KEEP | Anti-pattern with its reason. | no source |
| project-rules.md:101 | "Don't render `d-none` on controls and rely on JS to reveal." | KEEP | Anti-pattern with its reason. | no source |
| project-rules.md:102 | "Don't put Bootstrap `<script>` tags on individual pages." | DELETE | Repeats CLAUDE.md checkpoint 2. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:103 | "Don't inline a navbar on a `/manage/*` page" | DELETE | Repeats CLAUDE.md checkpoint 1. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:104 | "Don't expose backend keys to end users." | KEEP | Anti-pattern; not said elsewhere. | no source |
| project-rules.md:105 | "Don't scatter auth checks." | DELETE | Repeats CLAUDE.md checkpoint 4 and line 53 of this file. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:107 | "Don't write literal `<?=` / `<?php` / `<?` inside HTML comments or backticks in `.php` files." | KEEP | A real trap. Most of the text explains why (PHP reads <? even inside HTML comments); the incident is one sentence. | no source |
| project-rules.md:108 | "Don't assume `$db->query()` / `$db->prepare()` return `false` on error." | KEEP | The detail behind CLAUDE.md line 113. | no source |
| project-rules.md:114 | "Don't auto-fill `Colour` server-side on import" | KEEP | Code convention. | no source |
| project-rules.md:116 | "Server-side validation on every save path" | KEEP | Code convention. | no source |
| project-rules.md:118 | "`/manage/editor/api?action=bulk_import_zip` never overwrites existing songbook or song rows." | KEEP | Code convention. | no source |
| project-rules.md:120 | "Audit reports — committable summaries — live under `.importers/audits/`." | KEEP | Code convention. | no source |
| project-rules.md:122 | "GitHub auto-close does NOT fire." | KEEP | A fact Claude needs; not obvious from the code. | no source |
| project-rules.md:124 | "Pages that already do this:" | REWRITE | The rule (log the error and show admins the real exception) is good. The list of which pages do and do not yet is status that goes stale; drop it and point at #713. | [P9] "Information that changes frequently" |
| project-rules.md:126 | "New migration → register in THREE places (#708)." | REWRITE | Out of date and contradicts CLAUDE.md rule 19, which says those lists are now generated from one entry in migration-registry.php. Following this means hand-editing generated lists. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:128-133 | "Public form no-JS fallback (#711)." | KEEP | Code convention (includes single quotes in SQL). | no source |
| project-rules.md:135 | "Shared colour-picker partial (#715)." | KEEP | Code convention. | no source |
| project-rules.md:137-139 | "Misc-pinned-bottom + non-official alphabetical" | KEEP | Code convention. | no source |
| project-rules.md:141-143 | "register it in BOTH places, in deployment order, AND add the per-card UI block." | REWRITE | Same as line 126: replaced by the single registry entry (CLAUDE.md rule 19). | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:145 | "Activity Log local-time + UA wrapping" | KEEP | Code convention. | no source |
| project-rules.md:151 | "widen this SET, add the per-entity table" | KEEP | Adds a step CLAUDE.md lacks. | no source |
| project-rules.md:153 | "Don't add a new provider regex inline" | DELETE | Repeats CLAUDE.md checkpoints 11-12. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:155 | "Sortable headers are a `<th data-sort-key="…">` + the shared `js/modules/admin-table-sort.js`." | KEEP | Adds how sortable headers work, which CLAUDE.md lacks. | no source |
| project-rules.md:157 | "Bulk-promote curator workflow (#846)." | KEEP | Code convention. | no source |
| project-rules.md:159-162 | "path-filtered required checks + auto-merge is a footgun" | REWRITE | Three useful one-line lessons buried in incident stories. Keep the lessons, drop the stories. | [P9] "Long explanations or tutorials" |
| project-rules.md:164 | "Don't remove the fallback" | KEEP | Instruction not said elsewhere. | no source |
| project-rules.md:172-174 | "Always carry a per-entity breakdown alongside the aggregate" | KEEP | UI convention. | no source |
| project-rules.md:176-178 | "The 200 must reflect the actual side effect, not the local code path's intent." | KEEP | "Don't lie about side effects": a truth rule for what the app tells users. | no source |
| project-rules.md:180-203 | "A blank screen or "0%" indicator for any duration > 200ms is unacceptable." | KEEP | §13.3-13.6 (grouped UI conventions). | no source |
| project-rules.md:205-228 | "Bearer tokens, magic-link tokens, password-reset tokens, CSRF tokens" | KEEP | Security rule for logging. (Section order is odd: §11 sits after §13.) | no source |
| project-rules.md:236-244 | "do NOT keep iterating on the network/UA dimension." | KEEP | A real debugging order that prevents a known waste. | no source |
| project-rules.md:246-254 | "HTML void elements" | KEEP | Code convention (grouped). | no source |
| project-rules.md:256-273 | "Diagnostic sequence when "subdomain X has the data but Y doesn't":" | KEEP | Diagnostic steps (grouped). | no source |
| project-rules.md:277-287 | "every runtime read hits live MySQL" | DELETE | Repeats CLAUDE.md rule 17 almost word for word. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:289-296 | "Signed-in user data is DB-first auto-sync" | KEEP | Detail not in CLAUDE.md. | no source |
| project-rules.md:298-300 | "so an admin can never lock themselves out." | KEEP | Detail not in CLAUDE.md. | no source |
| project-rules.md:304-318 | "try the live docroot first, sibling as the CLI fallback" | REWRITE | Replaced by, and now contradicts, CLAUDE.md rule 41: line 306 says the sibling public_html/ path "works" for long-lived files, while rule 41 says it crashes on the dev and beta sites. Teach the IHYMNS_INCLUDES_DIR method instead. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:320-322 | "`[deploy all]` in the commit/PR-title still forces a full sweep" | KEEP | Deploy fact. | no source |
| project-rules.md:326 | "match the model tier to the complexity of the work." | KEEP | Model choice. | no source |
| project-rules.md:330 | "Fast / cheap tier (e.g. Haiku)" | KEEP | Model choice. | no source |
| project-rules.md:331 | "Mid tier (e.g. Sonnet)" | KEEP | Model choice. | no source |
| project-rules.md:332 | "Top tier (e.g. Opus)" | KEEP | Model choice. | no source |
| project-rules.md:334 | "When unsure, prefer the more capable tier for anything correctness-critical" | KEEP | Model choice. | no source |
| project-rules.md:336-339 | "use them rather than reinventing the routing" | KEEP | Points at the two agent files. (The deep-architect file itself is out of date; see its row.) | no source |
| project-rules.md:341 | "Standard mid-tier implementation work needs no special agent" | KEEP | Model choice. | no source |
| project-rules.md:343 | "Owner refinement (2026-09-23), which takes precedence here:" | REWRITE | Mostly history ("replaces the earlier Fable 5 first routing") and a restatement of standing-directives.md. Fold "Opus agents in sequence for deep work" into the list above and drop the rest. Flag: it also restates the review-until-clean loop. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:347-355 | "This section, `CLAUDE.md` rules #28/#29, `MEMORY.md` and many in-code" | REWRITE | Keep one sentence ("#1352/#1353/#1354 in code comments mean #1590"); the rest is how the mistake happened. Keep this copy and cut the CLAUDE.md one (rule 28 row). | [P9] "Long explanations or tutorials" |
| project-rules.md:357-365 | "One registry, one place." | KEEP | §18 intro and 18.1 (grouped detail). | no source |
| project-rules.md:367-375 | "Add ONE line to `TIER_CAPS`" | KEEP | The how-to. Keeping it here lets CLAUDE.md rule 28 shrink. | no source |
| project-rules.md:377-402 | "`checkTierAccess($userTier, $action, $hasCcli)`" | KEEP | §18.3-18.5 (grouped detail). | no source |
| project-rules.md:404-406 | "is the robust CSRF check for same-origin writes (#1352)." | DELETE | A near word-for-word copy of CLAUDE.md rule 29. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:408-422 | "The viewer struct — `includes/access_context.php`." | KEEP | §18.7, P3 and P4 (grouped detail not in CLAUDE.md). | no source |
| project-rules.md:430-436 | "A pair-existence check is what actually catches it" | KEEP | §19.1, the detail behind CLAUDE.md line 118. | no source |
| project-rules.md:438-447 | "listens for `error` and `unhandledrejection`" | KEEP | §19.2 (grouped detail). | no source |
| project-rules.md:449-456 | "of `CHANGELOG.md` into `appWeb/public_html/data/whats-new.md` on every deploy" | REWRITE | Out of date: the deploy now reads WHATS-NEW.md (CHANGELOG.md only if that file is missing) and keeps up to 5 sections (deploy.yml:611-621; whats-new-style.md:3-8). The "top of CHANGELOG.md is user-facing" warning no longer holds. Keep the markdown_lite.php point. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:458-501 | "302, never 301." | KEEP | §20.1-20.4 (grouped). | no source |
| project-rules.md:502-511 | "clearing chords requires `[]` (an empty array replaces);" | REWRITE | Half out of date: since the #2087 fix, component_upsert treats an explicit null as "clear" for chords (api2.php:3459 now uses array_key_exists), and CLAUDE.md rule 51 says "pass an explicit null to clear it". The trap still holds for per-line languages. Fix the chords half. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| project-rules.md:513-525 | "Arrangement ordinals index the ASSEMBLED list" | KEEP | §20.6 code convention. | no source |
| project-rules.md:529-542 | "a saved layout is a wish, not a contract" | KEEP | §21.1 code convention. | no source |
| project-rules.md:544-554 | "A 4th outbound integration copies this shape" | KEEP | §21.2. Still right; it does not mention the shared private-address check (CLAUDE.md rule 49) but does not contradict it. | no source |
| project-rules.md:558-569 | "owner has now had to ask **four** separate times" | DELETE | History of the rule, and it disagrees with standing-directives.md §11 (four asks vs five). Its useful check is already at standing-directives.md:213-215. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:571-574 | "Write the way you would explain something to a capable colleague who does not work on this particular system." | DELETE | A copy of standing-directives.md §11 (and CLAUDE.md:9). | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:576-580 | "Why it keeps slipping." | DELETE | Explanation; gives Claude nothing to do. | [P9] "Long explanations or tutorials" |
| project-rules.md:582-584 | "would somebody who has never opened this codebase know what I just said?" | DELETE | Repeats standing-directives.md:209-211. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:586-587 | "is lower the technical bar." | DELETE | Repeats standing-directives.md:231-232. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| project-rules.md:589-596 | "an idempotent upsert keyed on the natural key" | KEEP | The only part of §22 not said elsewhere. Examples work better than rules. | [P6] "Positive examples of the communication style you want tend to be more effective than instructions about what not to do." |
| project-rules.md:598-600 | "Say plainly what is finished, what is not, what you did not check" | KEEP | Truth rule. A copy of AGENTS.md:36-38, but truth rules always stay. (The first-pass inventory said DELETE; overridden by your rule 8.) | [P7] "Only if no real check can run here, say which one you did not run and why instead of reporting the change as done." |

### agents/deep-architect.md

`.claude/agents/deep-architect.md` — 8 lines. 0 DELETE, 2 REWRITE, 3 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| agents/deep-architect.md:3 | "Use ONLY for genuinely hard reasoning" | REWRITE | project-rules.md:339 calls this agent "the hard-reasoning / review / security lane", but this description leaves out review and security, so Claude will not send that work here. Add them. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| agents/deep-architect.md:4 | "model: claude-fable-5" | REWRITE | Out of date: standing-directives.md §1 (2026-09-23) moved deep analysis and planning to Opus. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| agents/deep-architect.md:7 | "You handle complex reasoning." | KEEP | Role line. The guidance supports role lines and none supports deleting one. (The first-pass inventory said DELETE; overridden by your rule 6.) | [P4] "Setting a role in the system prompt focuses Claude's behavior and tone for your use case. Even a single sentence makes a difference" |
| agents/deep-architect.md:7-8 | "Weigh trade-offs explicitly and explain the decision before implementing." | KEEP | Says what to hand back. | no source |
| agents/deep-architect.md:8 | "Prefer correctness over speed." | KEEP | Sets a priority. It is not a "think harder" or "verify again" rule, and no quote supports deleting it. | no source |

### agents/quick-edits.md

`.claude/agents/quick-edits.md` — 8 lines. 0 DELETE, 1 REWRITE, 4 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| agents/quick-edits.md:3 | "Use PROACTIVELY for mechanical, low-reasoning work" | REWRITE | "Proactively" invites handing off tiny edits Claude could finish itself in a few steps, which the guidance says not to do. Say: use it for batches of mechanical edits too big to do directly. | [P6] "Do not delegate work you can finish yourself in a handful of tool calls, and do not use subagents to verify or double-check your own work." |
| agents/quick-edits.md:4 | "model: claude-haiku-4-5" | KEEP | Not checked whether a newer Haiku exists (see "Not run"). | no source |
| agents/quick-edits.md:7 | "You handle fast, mechanical changes." | KEEP | Role line; supported by the guidance. | [P4] "Setting a role in the system prompt focuses Claude's behavior and tone for your use case. Even a single sentence makes a difference" |
| agents/quick-edits.md:7-8 | "Make the edit, verify it builds or compiles if relevant, then stop." | KEEP | A real check and a clear finish line. Not verify-twice. | [P9] "Have Claude show evidence rather than asserting success" |
| agents/quick-edits.md:8 | "Do not redesign or refactor beyond what was asked." | KEEP | Holds scope. | [P7] "Don't add features, tests, files, docs or refactors that weren't asked for. If you think one would help, mention it at the end instead of doing it." |

### prompt-polish/SKILL.md

`.claude/skills/prompt-polish/SKILL.md` (third-party skill, pinned in `skills-lock.json`) — 88 lines. 1 DELETE, 3 REWRITE, 20 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| prompt-polish/SKILL.md:3 | "Supports Claude Fable 5 and Claude Opus 4.8 (Opus 4.7 routes to 4.8 guidance)." | REWRITE | No guidance for Opus 5.5, the model you now use for deep work. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P4] "Where a technique names a specific model, treat it as measured on that model and re-check it against your own evals before applying it to another." |
| prompt-polish/SKILL.md:10 | "Core belief: polishing is mostly subtraction." | KEEP | Matches the guidance on pruning. | [P9] "Ruthlessly prune. If Claude already does something correctly without the instruction, delete it or convert it to a hook." |
| prompt-polish/SKILL.md:14-19 | "Accept any of these shapes:" | KEEP | How to call the skill. | no source |
| prompt-polish/SKILL.md:21-27 | "`opus`, `opus 4.8`, `opus48`, `4.8`" | REWRITE | A request that just says "opus" (including Opus 5.5) is sent to the Opus 4.8 guidance without saying so. Advice measured on one model should not be applied to another unchecked. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P4] "Where a technique names a specific model, treat it as measured on that model and re-check it against your own evals before applying it to another." |
| prompt-polish/SKILL.md:29 | "Model missing → default to Fable 5 and say so in the Note line." | KEEP | The skill's own default; not wrong. Note: your deep work now runs on Opus, so you may want a different default (needs an upstream change). | no source |
| prompt-polish/SKILL.md:30 | "Prompt missing → ask for it. Never polish an imagined prompt." | KEEP | Truth rule. Always kept. | no source |
| prompt-polish/SKILL.md:31 | "Never fake model-specific guidance." | KEEP | Truth rule. Always kept. | no source |
| prompt-polish/SKILL.md:35 | "Read ONLY the routed reference file" | KEEP | Keeps the skill small. | no source |
| prompt-polish/SKILL.md:37-41 | "Diagnose before touching anything." | KEEP | Skill method. | no source |
| prompt-polish/SKILL.md:43-48 | "Pick the smallest tier that fits." | KEEP | Checked for "don't overthink": this is about prompt length, not about reasoning less. | no source |
| prompt-polish/SKILL.md:50 | "If the rewrite would need more than four placeholders, the task itself is underspecified — ask instead" | KEEP | Asks rather than guesses. | no source |
| prompt-polish/SKILL.md:52 | "Walk the hard gate below. Fix violations before output, not after." | DELETE | Flag: verify-twice. A final "check your output against the list" step. The guidance says to remove these on current Opus models rather than rewrite them. The gate itself (lines 57-61) stays as the rules the output must meet. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P6] "If your prompt contains explicit verification instructions ("include a final verification step for any non-trivial task," "use a subagent to verify"), remove them" |
| prompt-polish/SKILL.md:57 | "The polished prompt asks for the same task as the raw prompt." | KEEP | Holds scope. | no source |
| prompt-polish/SKILL.md:58 | "user intent outranks model doctrine." | KEEP | Sets priority. | no source |
| prompt-polish/SKILL.md:59 | "Nothing is invented" | KEEP | Truth rule. Always kept. | no source |
| prompt-polish/SKILL.md:60 | ""Be thorough", "think step by step", and restated defaults are noise on these models." | KEEP | Agrees with the guidance. Note: it contradicts standing-directives.md:20 ("Think hard before acting"); see that row. | [P4] "If your prompts previously encouraged the model to be more thorough or use tools more aggressively, dial back that guidance." |
| prompt-polish/SKILL.md:61 | "Never instruct the target model to reveal, transcribe, or echo its chain-of-thought." | KEEP | Skill rule. | no source |
| prompt-polish/SKILL.md:66-70 | "Emit, in order, and nothing else:" | KEEP | Output format. | no source |
| prompt-polish/SKILL.md:72 | "No preamble, no "here's your polished prompt", no explanation of changes." | KEEP | Output format; also "ask instead of guessing". | no source |
| prompt-polish/SKILL.md:78-80 | "turning a two-line question into an agent operating manual." | KEEP | Anti-patterns (inflation, boilerplate, drift). | no source |
| prompt-polish/SKILL.md:81 | "keeping prompt cargo-cult ("you are a world-class expert", "take a deep breath")" | REWRITE | Treats role lines as junk; current guidance says a role, even one sentence, helps. Keep "take a deep breath" as junk; stop calling roles junk. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P4] "Setting a role in the system prompt focuses Claude's behavior and tone for your use case. Even a single sentence makes a difference" |
| prompt-polish/SKILL.md:82 | "micromanaging Fable 5 with steps it doesn't need" | KEEP | Anti-pattern. | no source |
| prompt-polish/SKILL.md:86 | "Never write doctrine from memory." | KEEP | Truth rule. Always kept. | no source |
| prompt-polish/SKILL.md:87-88 | "Add the alias row to the table above." | KEEP | How to extend. | no source |

### prompt-polish/references/fable-5.md

`.claude/skills/prompt-polish/references/fable-5.md` (third-party) — 227 lines. 1 DELETE, 2 REWRITE, 10 KEEP, 0 for you to decide.

Most of this file is sample text to paste into other prompts, not instructions to Claude; those parts are grouped.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| prompt-polish/references/fable-5.md:3 | "never paste it mechanically." | KEEP | Skill method. | no source |
| prompt-polish/references/fable-5.md:9-14 | "Built for the hardest version of the task." | KEEP | Facts about Fable 5, labelled as such. | no source |
| prompt-polish/references/fable-5.md:18-25 | "Fable 5 already does these." | KEEP | Fable-specific "delete on sight" list. | no source |
| prompt-polish/references/fable-5.md:26 | "Role-play credentials ("you are a world-class senior engineer")" | REWRITE | Lists role lines as noise; the guidance supports them. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P4] "Setting a role in the system prompt focuses Claude's behavior and tone for your use case. Even a single sentence makes a difference" |
| prompt-polish/references/fable-5.md:30 | "Apply only the rows the diagnosis triggered." | KEEP | Skill method. | no source |
| prompt-polish/references/fable-5.md:32-46 | "Add a verification cadence with fresh-context verifier subagents (snippet 10)" | KEEP | Decision table (grouped). Flag: one row recommends verifier helpers. That is advice for prompts aimed at Fable 5; my "remove verification steps" quotes are about Opus 5, so no quote covers it. Kept. | no source |
| prompt-polish/references/fable-5.md:52-127 | "When you have enough information to act, act." | KEEP | Snippets 1-9 (grouped): text for other prompts, not instructions to Claude. | no source |
| prompt-polish/references/fable-5.md:129-135 | "verify the work against the specification using fresh-context subagents" | KEEP | Flag: verify-twice style, but it is text for prompts aimed at Fable 5 (same reasoning as lines 32-46). | no source |
| prompt-polish/references/fable-5.md:137-162 | "You are operating autonomously." | KEEP | Snippets 11-13 (grouped): text for other prompts. | no source |
| prompt-polish/references/fable-5.md:166-169 | "do not silently polish into a refusal" | KEEP | Skill rule. | no source |
| prompt-polish/references/fable-5.md:173-205 | "Done means: [verifiable completion condition]." | KEEP | Skeletons (grouped): text for other prompts. | no source |
| prompt-polish/references/fable-5.md:207-216 | "suppress speculation unless the potential impact is severe." | REWRITE | Flag: severity filter. Tells reviewers to hold back findings unless severe. Report everything with a confidence level and filter afterwards (the Opus 4.8 file's snippet 7 does this). (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P6] "ask it to report everything and filter in a separate pass instead." |
| prompt-polish/references/fable-5.md:219-227 | "Before emitting, confirm the polished prompt:" | DELETE | Flag: verify-twice. A second checklist run before output; SKILL.md's gate already states the rules. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P6] "If your prompt contains explicit verification instructions ("include a final verification step for any non-trivial task," "use a subagent to verify"), remove them" |

### prompt-polish/references/opus-4-8.md

`.claude/skills/prompt-polish/references/opus-4-8.md` (third-party) — 218 lines. 1 DELETE, 2 REWRITE, 9 KEEP, 0 for you to decide.

Same as above: sample text is grouped.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| prompt-polish/references/opus-4-8.md:3 | "Opus 4.7 requests route here too" | KEEP | Says what the file covers. | no source |
| prompt-polish/references/opus-4-8.md:9-16 | "Literal, explicit instruction follower" | KEEP | Facts about Opus 4.8, labelled as such. Line 15 agrees with your "report everything" preference. | no source |
| prompt-polish/references/opus-4-8.md:20-26 | "Opus 4.8 already does these; instructions demanding them are noise:" | KEEP | Opus 4.8 list. Line 22 calls forced status updates noise for that model; your progress tables are a deliberate choice, which newer guidance says the model follows. | no source |
| prompt-polish/references/opus-4-8.md:27 | "Role-play credentials and "think step by step" rituals" | REWRITE | The role half conflicts with the guidance, which supports roles. Keep the "think step by step" half. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P4] "Setting a role in the system prompt focuses Claude's behavior and tone for your use case. Even a single sentence makes a difference" |
| prompt-polish/references/opus-4-8.md:31-45 | "Separate coverage from filtering (snippet 7)" | KEEP | Decision table (grouped). | no source |
| prompt-polish/references/opus-4-8.md:49-114 | "Apply [instruction] to every [item] in [scope], not just the first one." | KEEP | Snippets 1-6 (grouped): text for other prompts. | no source |
| prompt-polish/references/opus-4-8.md:116-125 | "Report every issue you find, including ones you are uncertain about or consider low-severity." | KEEP | Exactly the "report everything, filter later" approach you want. | [P6] "ask it to report everything and filter in a separate pass instead." |
| prompt-polish/references/opus-4-8.md:127-132 | "only omit nits like pure style or naming preferences." | REWRITE | Flag: severity filter applied during the review. Prefer snippet 7 above (report everything, filter later). (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P6] "ask it to report everything and filter in a separate pass instead." |
| prompt-polish/references/opus-4-8.md:134-153 | "Done means: [verifiable condition]." | KEEP | Snippets 8-10 (grouped). Snippet 9 ("Think carefully") is only for when effort cannot be raised, which agrees with "effort first". | no source |
| prompt-polish/references/opus-4-8.md:155-162 | "If output seems shallow, raise effort before prompting around it." | KEEP | Agrees with the guidance that effort is the main control. | [P5b] "The model decides for itself how much to think, and effort is the main control." |
| prompt-polish/references/opus-4-8.md:164-208 | "Output only [format]; no commentary." | KEEP | Skeletons (grouped): text for other prompts. | no source |
| prompt-polish/references/opus-4-8.md:210-218 | "Before emitting, confirm the polished prompt:" | DELETE | Flag: verify-twice. Same as fable-5.md:219-227. (Third-party skill: a local edit is overwritten by `npx skills update`, so this needs an upstream change or a local copy.) | [P6] "If your prompt contains explicit verification instructions ("include a final verification step for any non-trivial task," "use a subagent to verify"), remove them" |

### prompt-polish/agents/openai.yaml

`.claude/skills/prompt-polish/agents/openai.yaml` (third-party) — 4 lines. 0 DELETE, 0 REWRITE, 1 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| prompt-polish/agents/openai.yaml:4 | "default_prompt: "prompt-polish/FABLE 5/[paste your rough prompt]"" | KEEP | Saved prompt for the skill picker; same default as SKILL.md:29. | no source |

### .OpenAI/README.md

`.OpenAI/README.md` — 23 lines. 0 DELETE, 0 REWRITE, 3 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| .OpenAI/README.md:14-15 | "There is one handoff, in `.claude/sessions/<date>-HANDOFF.md`" | KEEP | Prevents a second handoff. | no source |
| .OpenAI/README.md:16-18 | "This folder points at them rather than repeating them" | KEEP | Mostly true; MEMORY.md:24-26 is the one place that repeats a rule (see that row). | no source |
| .OpenAI/README.md:22-23 | "fix anything in `CONTEXT.md` that has stopped being true." | KEEP | Keeps the notes current. | no source |

### .OpenAI/CONTEXT.md

`.OpenAI/CONTEXT.md` — 45 lines. 0 DELETE, 1 REWRITE, 4 KEEP, 1 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| .OpenAI/CONTEXT.md:11-19 | "The project wiki, kept inside this repository" | KEEP | A short folder map, like CLAUDE.md:162-168 (marked DELETE). Kept here because its descriptions say things a listing does not (which tests are guards; the wiki is in-repo), and it is not in an always-loaded file. | no source |
| .OpenAI/CONTEXT.md:21-29 | "Read these first, in this order" | KEEP | Where to start. | no source |
| .OpenAI/CONTEXT.md:33-36 | "Pushing to `alpha` deploys the development site." | KEEP | A fact Codex must know before pushing. | no source |
| .OpenAI/CONTEXT.md:42-43 | "it reviews again until it finds nothing" | REWRITE | Flag: verify-twice. Copy of the loop with no end point (standing-directives.md:271). Change both together. | [P9] "Tell the reviewer to flag only gaps that affect correctness or the stated requirements, and treat the rest as optional." |
| .OpenAI/CONTEXT.md:44-45 | "If you take over, record that in the handoff, and keep the handoff up to date as you go." | KEEP | Fallback rule. | no source |

### .OpenAI/MEMORY.md

`.OpenAI/MEMORY.md` — 32 lines. 2 DELETE, 0 REWRITE, 6 KEEP, 2 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| .OpenAI/MEMORY.md:3-4 | "disagree, the handoff wins. Fix this file when that happens." | KEEP | A clear tie-breaker, which the guidance says matters because conflicting notes get resolved at random. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| .OpenAI/MEMORY.md:10-12 | "An empty review is not a clean review." | KEEP | Truth rule. Always kept. | no source |
| .OpenAI/MEMORY.md:13-14 | "Codex was at its usage limit from 14 to 20 September 2026." | DELETE | Time-bound status; it lives in issue #2123 and the handoff. | [P9] "Information that changes frequently" |
| .OpenAI/MEMORY.md:15-16 | "because `alpha` is not the default branch. Close the issue by hand." | KEEP | A fact Codex needs. | no source |
| .OpenAI/MEMORY.md:24-26 | "follows a line's identity number, never its position" | DELETE | Codex already reads this rule in AGENTS.md:69-75, and README.md:16-18 says this folder points at rules rather than repeating them. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |
| .OpenAI/MEMORY.md:27 | "There is no whole-catalogue JSON file (rule #17)." | KEEP | Short pitfall with a pointer. | no source |
| .OpenAI/MEMORY.md:28-29 | "A page fragment can never run an inline `<script>`." | KEEP | Short pitfall with a pointer. | no source |
| .OpenAI/MEMORY.md:30-32 | "Pull request titles must start with a short label saying what kind of change it is" | KEEP | Plain-words version of the pull-request title rule (CLAUDE.md rule 46), which decides version numbers. | no source |

### whats-new-style.md

`.claude/whats-new-style.md` — 58 lines. 0 DELETE, 2 REWRITE, 8 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| whats-new-style.md:3-8 | "is written for **worshippers and worship leaders**, not developers." | KEEP | Audience and source file; both still true (deploy.yml:620-621). | no source |
| whats-new-style.md:10-15 | "the how never appears in the source at all." | KEEP | Security reason for keeping internals out. | no source |
| whats-new-style.md:19-21 | "the benefit, not the implementation." | KEEP | Writing rule. | no source |
| whats-new-style.md:22-23 | "Use the same structure the app parses" | KEEP | Matches the real file (headings like "## 1.3.0 — 30 August 2026"). (The first-pass inventory suggested "MAJOR.MINOR"; the real headings use three-part numbers, so kept.) | no source |
| whats-new-style.md:27-33 | "File or path names" | KEEP | "Must not contain" list; a security rule. | no source |
| whats-new-style.md:37 | "Add a new `## <version> — <friendly date>` section at the TOP of `WHATS-NEW.md`." | REWRITE | CLAUDE.md rule 46 says a patch release gets no heading of its own (its bullet goes under the current one). This step adds a section for every release. Say "only when the minor or major version goes up". | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| whats-new-style.md:38-39 | "most technical/internal/dormant items collapse to nothing here." | KEEP | Writing rule. | no source |
| whats-new-style.md:40-41 | "Keep it to a handful of bullets." | KEEP | Length rule. | no source |
| whats-new-style.md:43-55 | "Adding a bullet to a release that already exists: put it at the TOP of that" | REWRITE | The rule matters (bullets past ten are silently dropped). Keep it with one line of reason; the walk-through of the deploy settings can go, since a test already guards them. | [P9] "Long explanations or tutorials" |
| whats-new-style.md:57-58 | "couldn't picture what it means, cut it or rewrite it as a plain benefit." | KEEP | A simple test. | no source |

### admin-plain-english.md

`.claude/admin-plain-english.md` — 83 lines. 0 DELETE, 0 REWRITE, 11 KEEP, 0 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| admin-plain-english.md:5-9 | "It governs **every word a non-developer reads**" | KEEP | Scope. | no source |
| admin-plain-english.md:11-13 | "No jargon, no file/table/function names, no issue numbers, no version internals." | KEEP | Writing rule. | no source |
| admin-plain-english.md:14-20 | "When unsure whether a detail helps a user or only helps an attacker, leave it out." | KEEP | Security rule for what users see. | no source |
| admin-plain-english.md:22-24 | "never the old internal name, and never the underlying route/table." | KEEP | Writing rule. | no source |
| admin-plain-english.md:30-35 | "every label and description in `/manage/*` must be plain English." | KEEP | Writing rule. | no source |
| admin-plain-english.md:37-39 | "per-page header on every `/manage/*.php`" | KEEP | Says where it applies. | no source |
| admin-plain-english.md:43-49 | "Read like help text a volunteer could follow." | KEEP | Writing rule. | no source |
| admin-plain-english.md:53-60 | "File/path/function/class names" | KEEP | "Must not contain" list. | no source |
| admin-plain-english.md:64-68 | "Plain does not mean vague or wrong." | KEEP | Truth rule. Always kept. | no source |
| admin-plain-english.md:72-74 | "the header is the priority and the minimum." | KEEP | Sets where a pass ends. Checked: it limits how much copy to clean, not what gets reported. | no source |
| admin-plain-english.md:78-83 | "Content Lock Safety Check" | KEEP | Before/after examples. | [P6] "Positive examples of the communication style you want tend to be more effective than instructions about what not to do." |

### .claude/README.md

`.claude/README.md` — 29 lines. 0 DELETE, 4 REWRITE, 5 KEEP, 1 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| .claude/README.md:9-13 | "Project memory: modularity rule + top-level guardrails." | KEEP | File table (grouped). Accurate, though it leaves out standing-directives.md, standing-tasks.md, agents/ and skills/. | no source |
| .claude/README.md:14 | "Picked up by `/resume` on any dev device with the repo checked out." | REWRITE | sessions/README.md:17-38 says logs could never be picked up this way and have not been committed since 2026-09-07. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| .claude/README.md:15 | "Historical per-project notes." | KEEP | File table. | no source |
| .claude/README.md:19 | "that's user-specific, not project policy." | REWRITE | The repo now installs rules into ~/.claude/CLAUDE.md (standing-directives.md:250-252). | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| .claude/README.md:20 | "Currently none defined." | REWRITE | Two agents exist in .claude/agents/, and project-rules.md:336-339 tells Claude to use them. | [P8] "if two instructions contradict each other, Claude may pick one arbitrarily." |
| .claude/README.md:25 | "append to `project-rules.md`, and if it's a red-flag check, also surface it in `CLAUDE.md`." | KEEP | Says where new rules go. | no source |
| .claude/README.md:26 | "list it in the table under §1.1 of `project-rules.md`" | KEEP | Says where new modules go. | no source |
| .claude/README.md:27 | "add to §9 of `project-rules.md` with the concrete example." | KEEP | Says where anti-patterns go. | no source |
| .claude/README.md:29 | "If something was important enough to discover the hard way, it's important enough to write down here." | REWRITE | Pushes toward writing everything down; the guidance's test is the reverse. Say: write it down once, in one place, if leaving it out would cause a mistake. | [P9] "For each line, ask: "Would removing this cause Claude to make mistakes?" If not, cut it." |

### sessions/README.md

`.claude/sessions/README.md` — 99 lines. 1 DELETE, 1 REWRITE, 5 KEEP, 2 for you to decide.

| Where (file:line) | Your line (short exact quote) | Verdict | Why (plain English) | Anthropic's line |
|---|---|---|---|---|
| sessions/README.md:7-8 | "It says what landed, what did not, what was not checked, what is still open, and where to pick up." | KEEP | Truth rule for handoffs. Always kept. | no source |
| sessions/README.md:11 | "Ten of them together come to about 224 KB." | DELETE | Stale count (there are now 15 files). The next sentence ("every other document points at these, never at a raw log") becomes true again once CLAUDE.md:233-238 is fixed. | [P9] "Information that changes frequently" |
| sessions/README.md:14-15 | "Follow the shape of the most recent one" | KEEP | Handoff format. | no source |
| sessions/README.md:17-38 | "They were committed for about five months" | REWRITE | Two sentences are enough: raw logs are not committed (since #2096) because Claude Code only finds them at a path specific to each computer; use a handoff, or --restore. | [P9] "Long explanations or tutorials" |
| sessions/README.md:42 | "write a handoff note." | KEEP | What to do instead. | no source |
| sessions/README.md:44-54 | "`--restore` works out the right folder" | KEEP | How to move a conversation. | no source |
| sessions/README.md:56-57 | "a handoff note is often the better tool even when you have the log." | KEEP | Practical advice. | no source |

### The 5 additions

The five instructions you asked about. None exists today in a usable form, and each has a source. They are not counted in the verdicts above. Suggested wording is ready to paste.

| # | Paste this | Where | Anthropic's line |
|---|---|---|---|
| 1 | **Keep answers short.** "Keep replies short. Lead with the answer or the outcome, keep caveats brief, and leave out anything the reader doesn't need to act on. Plain words still apply: use more words only where they make a point clearer, never to repeat it." | CLAUDE.md plain-English section (replacing "Using more words is fine, and preferred"); AGENTS.md:24-25; device-level-rules.md | [P4] "Prompt explicitly for conciseness instead." [P6] "Keep responses focused, brief, and concise. Keep disclaimers and caveats short, and spend most of the response on the main answer." |
| 2 | **Cap document length.** "Make every document as long as its job needs and no longer: no filler sections, no repeated summaries. Hard limits: CLAUDE.md under 200 lines; a rule is one to three sentences plus the name of the test that guards it; a handoff's "Current state" fits on one screen. History, dates and incident stories go in the issue or the commit message, not the rule." | standing-tasks.md §5–§6; CLAUDE.md "Other references" | [P6] "Match the length of written documents to what the task needs: cover the substance, but do not pad with filler sections, redundant summaries, or boilerplate." [P8] "target under 200 lines per CLAUDE.md file. Longer files consume more context and reduce adherence." |
| 3 | **How to update me while you work.** "Before your first tool call, say in one sentence what you're about to do. While working, give a one-line update only when you finish a task, find something important, change direction, or need me. Show the task table at the start and the end of a long run, not on every change of state." | standing-directives.md §12 (replacing "whenever a task changes state"); AGENTS.md:59 | [P6] "Before your first tool call, say in one sentence what you're about to do. While working, give a brief update only when you find something important or change direction." [P5b] "say so in the system prompt; the model is responsive to such instructions." |
| 4 | **Hold the task scope.** "Do what was asked, at the size it was asked. Don't add features, tests, files, docs or refactors that weren't requested. If one would help, list it under Found at the end and file an issue instead of doing it." | CLAUDE.md commit section; AGENTS.md; standing-directives.md:145-146 (the "suggest new features" line) | [P7] "Don't add features, tests, files, docs or refactors that weren't asked for. If you think one would help, mention it at the end instead of doing it." [P6] "Deliver what was asked, at the scope intended" |
| 5 | **Limit the helpers you spawn.** "Use a helper agent only for work that is big or separate enough to be worth handing off, never for something you can finish in a few steps, and never to re-check your own work (the Codex review is the review). Run at most four at once, and say in the end report how many you started and why." | standing-directives.md §1, next to the workflow permission | [P6] "Do not delegate work you can finish yourself in a handful of tool calls, and do not use subagents to verify or double-check your own work." [P7] "don't launch reviewer sub-agents unless the user asked for a review" |

**The end-of-run report you asked for** (separate from the five): "At the end of every long run, report under three headings: **Blocked on me** (decisions only I can make, each with your recommendation), **Changed** (what you changed, with commits and links), **Found** (problems or ideas you noticed but didn't act on, each with its issue link). Lead with one sentence saying what happened." Source: [P5a] "say plainly what it did, what it found, and what it needs from you." [P6] "When you finish, lead with the outcome". Put it in AGENTS.md next to "When reporting on work done", and in device-level-rules.md so every project gets it.

## 3. For you to decide

These are safety and permission rules: branches, pull requests that auto-merge and deploy, force-push, deleting branches, a destructive database change, secrets in transcripts, and an owner-only switch. They get no verdict. Three things cut across several of them:

- **Opening pull requests conflicts.** project-rules.md:96 says never open one unless you ask. standing-directives.md:50-52 says open the single pull request to `alpha` when the work is done, and CLAUDE.md:173/176 assume Claude opens them. This matters because `.github/workflows/auto-merge-alpha.yml` turns on auto-merge for pull requests into `alpha`, and a merge into `alpha` deploys the development site.
- **"Commit to the existing working branch" has two answers today.** The remote has two working branches right now: `chore/deps-consolidate-2139-2140` and `feat/bcp47-language-policy`. The rules do not say what to do when more than one exists.
- **No file lists the general "always ask first" cases** (deleting data, secrets, spending money, deploying, publishing). Only the specific cases below exist. The autonomy rules at AGENTS.md:60-61 and standing-directives.md:111-112 are marked REWRITE to add a "before a risky or destructive step" stop, but which steps count is your call.

| # | Where | Your line (exact quote) | What it protects | Note |
|---|---|---|---|---|
| 1 | CLAUDE.md:173 | "One PR per piece of work, multiple commits inside it." | Fewer deploys and fewer merge races. | Assumes Claude opens pull requests; project-rules.md:96 says never open one unless asked. |
| 2 | CLAUDE.md:176 | "Stacked PRs (PR-B depends on PR-A landing first) are an exception, reserved for genuinely sequential dependencies" | Merge races. | Conflicts with standing-directives.md:38 ("Do not create multiple/stacked PRs"). |
| 3 | CLAUDE.md:177 | "a second working branch is what causes the merge races, and it needs the owner's permission." | Merge races; your control over branches. | The remote has two working branches right now (chore/deps-consolidate-2139-2140 and feat/bcp47-language-policy), and "if one already exists, commit to it" does not say which. |
| 4 | CLAUDE.md:178 | "never force-push main/alpha, never amend merged commits." | Shared history on the live branches. | Clear; no conflict found. |
| 5 | CLAUDE.md:179 | "test is the **tree diff**: `git diff origin/alpha origin/<branch>`" | Deleting a branch that still holds unmerged work. | Same check as .OpenAI/MEMORY.md:17-18; no conflict. |
| 6 | CLAUDE.md:50 | "The destructive drop is `'manual' => true` in the registry" | The shared live database (a column drop cannot be undone without the recovery script). | Clause inside rule 25; also said at line 114. |
| 7 | CLAUDE.md:114 | "never auto-run the destructive `retire-component-lines-json` drop" | Same as above. | Second copy of the line 50 clause. |
| 8 | CLAUDE.md:236-238 | "git add .claude/sessions/ && git commit -m "chore(sessions): sync"" | Secrets in session transcripts (review before committing). | Out of date: sessions/README.md:17 says raw logs have not been committed since 2026-09-07, and .claude/README.md:21 says raw unscrubbed transcripts never go in. This block still tells Claude to commit them. |
| 9 | CLAUDE.md:241 | "Always review the diff." | Secrets the scrubber misses. | Fine on its own; tied to the out-of-date block above. |
| 10 | AGENTS.md:49-50 | "Only create one if none exists" | Merge races. | Codex copy of the one-branch rule; same "which branch?" gap as CLAUDE.md:177. |
| 11 | standing-directives.md:36-39 | "Do not create multiple/stacked PRs" | Merge races. | Conflicts with CLAUDE.md:176, which allows stacked PRs as an exception. |
| 12 | standing-directives.md:47-49 | "and it needs explicit owner permission." | Your control over branches. | Same rule as CLAUDE.md:177. |
| 13 | standing-directives.md:50-52 | "and open the single PR to `alpha` when the work is done." | Keeps work moving without asking. | Conflicts with project-rules.md:96 ("Never open a PR unless the user explicitly asks"). This matters for safety: .github/workflows/auto-merge-alpha.yml turns on auto-merge for pull requests into alpha, and a merge into alpha deploys the development site. |
| 14 | standing-directives.md:62 | "Any output = commit to that branch instead." | Merge races. | With two branches on the remote today, "that branch" has two answers. |
| 15 | standing-directives.md:84 | "If no working branch exists, create one first." | Keeps work moving. | Repeats lines 50-52. |
| 16 | project-rules.md:96 | "Never open a PR unless the user explicitly asks for one." | Unreviewed merges and deploys (see auto-merge note above). | Conflicts with standing-directives.md:50-52 and the "Only stop to ask" rules in AGENTS.md:60-61 and standing-directives.md:111-112. |
| 17 | project-rules.md:106 | "Don't commit stacked PRs that re-implement work already in a parallel branch." | Duplicate work across branches. | "Rebase and reuse" may need a force-push to a working branch; the rules do not say whether that is allowed. |
| 18 | project-rules.md:166 | "PR target = `alpha`, batch as one PR." | Fewer deploys. | Restates CLAUDE.md:173; mostly history. |
| 19 | project-rules.md:424 | "stays owner-gated" | The live content-gating switch. | Clear. The rest of the line is a list of open decisions (#1772–#1777) that will go stale. |
| 20 | .OpenAI/CONTEXT.md:38 | "Work goes on **one** working branch, which is merged into `alpha` through a single pull request." | Merge races. | Codex copy; same PR-opening conflict as above. |
| 21 | .OpenAI/MEMORY.md:17-18 | "Delete it by hand, but only after `git diff origin/alpha origin/<branch>` prints nothing." | Deleting unmerged work. | Matches CLAUDE.md:179. |
| 22 | .OpenAI/MEMORY.md:19-20 | "Switching once deleted 1.6 GB of local files (#2108)." | Local files lost on a branch switch. | Same trap as sessions/README.md:72-99. |
| 23 | .claude/README.md:21 | "never. If you need session history in-repo, use `tools/sync-claude-session.sh`" | Secrets in transcripts. | Agrees with sessions/README.md; CLAUDE.md:236-238 still describes committing the scrubbed copies. |
| 24 | sessions/README.md:61-68 | "Before you share a log with anyone, read it." | Secrets in transcripts. | Clear; no conflict. |
| 25 | sessions/README.md:72-99 | "copy anything here that you care about to somewhere outside" | Local files lost on a branch switch. | Says it stops being a trap once #2097 lands; check whether it has. |

## 4. Not run / not checked

- **The three claude.dev blog posts** (getting-the-most-out-of-opus-5-5, building-with-claude-sonnet-5-5, how-we-made-claude-ai-faster). This environment's network rules blocked them. Nothing here is attributed to them.
- **Plugin prompts outside the repo.** `.claude/settings.json` turns on the `dev-team` and `codex` plugins; their own instructions live outside the repo and were not read.
- **`.claude/MEMORY.md` (666 lines) and the 15 files in `.claude/sessions/`** (14 handoffs plus one issue-sweep note). Not audited line by line. AGENTS.md:45 tells tools to read the newest handoff at the start, so its instructions matter.
- **Per-user memory files**: `.claude/projects/.../memory/*.md` and the installed `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` on your own computers. Not audited. The first-pass inventory noted two conflicts there (`feedback_claude_brief.md:7` says ProjectBrief.md is off-limits; `user_profile.md:9` asks for comments on "ideally every line"); I did not re-check them.
- **Today's polish-check additions** to CLAUDE.md, AGENTS.md, standing-tasks.md and device-level-rules.md were not audited (this audit covers the before-today versions).
- **No behavioural testing or evals.** None of the suggested changes was tried on a model to see whether behaviour actually improves. [P4] itself says to re-check model-specific advice "against your own evals".
- **Code facts.** I checked only the facts that drive a verdict: the wiki location (`wiki/` tracked, `iHymns.wiki/` absent), `package.json` test scripts, the What's New extraction in `deploy.yml`, the licence registry in `organisations.php`, chord clearing in `manage/editor/api2.php`, the auto-merge workflow, the two remote working branches, the `WHATS-NEW.md` heading format and the 15 session files. The other file paths, function names and rule details in CLAUDE.md's 51 rules were not checked against the code.
- **Whether `claude-haiku-4-5` is still the newest Haiku** (quick-edits.md:4). Not checked.
- **`tools/install-device-rules.sh`** was not re-read by me; the first-pass inventory says it holds no AI instructions.
- **Other instruction sources** (`.claude/commands/`, `.github/prompts/`, `.cursorrules`, workflow prompts): I relied on the first-pass inventory, which found none.
- **No tests or CI guards were run**, and no repo file other than this one was changed.
- **Accuracy check that was run:** a script checked every one of the 511 quotes in sections 2 and 3 against the cited lines of the cited file, and every Anthropic quote against `guidance-quotes.md`; all matched. I also compared 15 randomly chosen rows side by side with their source lines (all correct). The verdict counts in section 1 were recounted from the tables.
