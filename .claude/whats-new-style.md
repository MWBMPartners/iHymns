# "What's New" house style

The in-app **What's New** page (`/whats-new`) is written for **worshippers and
worship leaders**, not developers. Its source is the hand-written **`WHATS-NEW.md`**
at the repo root — the deploy turns the top few releases into
`appWeb/public_html/data/whats-new.md` and `includes/pages/whats-new.php` renders
it. (`CHANGELOG.md` stays the technical, developer-facing record and is only the
*fallback* source if `WHATS-NEW.md` is ever missing.)

Owner directive (2026-08-11): the What's New page must be **plain "average-Joe"
language with no technical inside information**. This is both a UX concern (users
don't care about internals) and a **security** one — a public changelog that names
files, endpoints, tables and mechanisms hands a would-be attacker a map of how the
system is built. So the rule is not "simplify the wording", it is **"the how never
appears in the source at all."**

## Every entry MUST

- Describe what a user can now **do**, or what got **better/faster/clearer** for
  them — the benefit, not the implementation.
- Read like an app-store "What's New" note: short, warm, concrete.
- Use the same structure the app parses: a `## <version> — <date>` heading and
  `- ` bullets. Bold the feature name (`- **Print and PDF** — …`).

## Every entry MUST NOT contain

- File or path names (`includes/…`, `js/…`, `manage/…`), function/class names.
- Commit SHAs, issue/PR numbers (`#1767`), branch names.
- Database tables/columns (`tblSongbooks`, `SongbookAbbr`), API endpoints/actions,
  config keys, settings flags, migration names.
- Framework/library/version internals, CSP/CSRF/auth mechanics, "dormant behind a
  flag", "gated", "resolver", "registry", and similar engineering vocabulary.
- Anything that reveals *how* a feature is implemented or *where* it lives.

## Writing a new release

1. Add a new `## <version> — <friendly date>` section at the TOP of `WHATS-NEW.md`.
2. Summarise only the **user-visible** changes from that release's `CHANGELOG.md`
   section — most technical/internal/dormant items collapse to nothing here.
3. Keep it to a handful of bullets. The deploy shows roughly the newest 5
   releases; older ones scroll off naturally, so there is no need to prune history.

**Adding a bullet to a release that already exists: put it at the TOP of that
release's list, not the bottom.** The deploy does not ship the whole file. It
copies at most the newest **5** release sections, at most **10** bullets from each
one, at most 50 bullets in total, and at most 48 KB — whichever limit it hits
first (the exact numbers are the four `WHATS_NEW_…` settings at the top of the
"Extract What's New for the app" step in `.github/workflows/deploy.yml` —
`WHATS_NEW_MAX_SECTIONS`, `WHATS_NEW_PER_SECTION`, `WHATS_NEW_MAX_ENTRIES` and
`WHATS_NEW_MAX_BYTES`; `tests/test-whats-new-extraction.js` lifts that
same trimming program straight out of the workflow file and runs it, so the test
cannot drift from what the deploy actually does). So if a release section
already has ten bullets and you append an eleventh at the end, it is quietly left
out of the page users see — no error, nothing to notice. Put new bullets first and
the oldest ones drop off instead, which is what you want anyway.

If in doubt whether a line is too technical: if a person who has never seen the
codebase couldn't picture what it means, cut it or rewrite it as a plain benefit.
