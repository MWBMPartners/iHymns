# Plain-English + minimal-disclosure house style (user-facing copy & docs)

## Standing scope (owner directive 2026-08-12) — applies to ALL user-facing writing

This house style is **not just the Manage sidebar/headers**. It governs **every
word a non-developer reads**: in-app Help/Guides (`help/*.md`, `help.php` topics),
the public site copy, `WHATS-NEW.md`, README/user-facing docs, tooltips, empty
states, error toasts — anything a **worshipper, curator/moderator, or a
non-technical admin** might see. Two rules, always together:

1. **Plain English.** Describe what a person can *do* and *why*, in everyday words.
   No jargon, no file/table/function names, no issue numbers, no version internals.
   If someone who has never seen the code can't follow it, rewrite it.
2. **Minimal disclosure (security).** User-facing copy MUST NOT reveal the
   *inner workings* — internal page routes, admin-only URLs, function/endpoint/
   table/column names, config flags, file paths, or "how the check/gate is
   implemented". That detail is a **map for a bad actor** (recon for an attack)
   and belongs only in developer docs (`CHANGELOG.md`, `.claude/`, code comments,
   the private Wiki). Describe the *benefit/behaviour*, never the mechanism.
   When unsure whether a detail helps a user or only helps an attacker, leave it out.

Reflect renamed things by their **new plain label** (e.g. "Find Duplicates",
"Edit History", "Role Permissions") — never the old internal name, and never the
underlying route/table.

---

## Manage (admin) plain-English house style

Owner directive (2026-08-11): **every label and description in `/manage/*` must be
plain English.** Even admins are not all technically minded. "Gating No-Op Verify"
with a description like *"Proves the content-gating completion (#1357/#1358) is a
byte-identical no-op while content_gating_enabled='0'…"* is exactly what we must
never ship. If a person who has never seen the codebase can't tell what a page is
for from its heading + one sentence, it's wrong.

This applies to the sidebar labels (`manage/includes/admin-links.php`) **and** the
per-page header on every `/manage/*.php`: the visible page **title** (`<h1>`) and
the **intro description** directly under it.

## Every page header MUST

- **Title**: a short, plain name for what the page manages or does. Prefer the
  sidebar label's plain wording (e.g. "Content Lock Safety Check", not "Gating
  No-Op Verifier").
- **Description**: one or two plain sentences — *what this page is for* and *when
  you'd use it*, in terms of songs, songbooks, people, services, permissions or
  the site — the benefit/purpose, not the mechanism.
- Read like help text a volunteer could follow.

## Every page header MUST NOT contain

- File/path/function/class names (`includes/…`, `content_gating.php`, `checkTierAccess`).
- Database tables/columns (`tblAccessTiers`, `content_gating_enabled`), config keys,
  settings flags, migration names, endpoint/action names.
- Commit SHAs, issue/PR numbers (`#1357`), branch names.
- Jargon: "no-op", "byte-identical", "dormant", "gated", "resolver", "registry",
  "idempotent", "entitlement" (say "permission"), "schema" (say "database
  structure"), "reflection", "CSP", "CSRF", "sentinel", "probe" (say "check").
- Version/phase internals ("#1769 P4", "WS-J").

## Keep it TRUE

Plain does not mean vague or wrong. Describe what the page actually does. If a page
is genuinely a technical/maintenance tool (database structure check, query tool,
safety check), say so plainly — "Check the database has the right structure",
"Run read-only lookups against the database", "Confirms turning content locking on
would change nothing yet" — don't pretend it's something friendlier than it is.

## Scope of a pass

Focus first on the page **header** (title + intro sentence) — that's the "what is
this" a user reads. Deeper in-page copy (card blurbs, button labels, inline help)
is worth cleaning too, but the header is the priority and the minimum.

## Worked before → after

| Before | After |
|---|---|
| Gating No-Op Verifier — "Proves the content-gating completion (#1357/#1358) is a byte-identical no-op while content_gating_enabled='0'…" | **Content Lock Safety Check** — "Before you ever turn content locking on, this confirms it would change nothing yet — every checked song still looks exactly the same." |
| Schema Audit — "Compares live DDL against schema.sql, flags orphan tables/columns." | **Database Structure Check** — "Checks the live database matches what iHymns expects, and flags anything missing or extra." |
| SQL Diagnostics | **Database Query Tool** — "Run read-only lookups against the database to investigate a problem." |
| Entitlements — "Per-role entitlement override matrix." | **Role Permissions** — "Choose what each type of user is allowed to do." |
