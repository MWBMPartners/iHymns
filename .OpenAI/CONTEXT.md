# iHymns — context for Codex

_Last updated: 2026-09-23._

## What iHymns is

A hymn and worship-song app. There is a website that also works as an installable, offline-capable
web app (PHP, plain JavaScript modules, Bootstrap 5, MySQL). There are also native Apple apps (iOS,
iPadOS, tvOS, in Swift) and an Android app (which also covers Amazon Fire devices, in Kotlin).

| Folder | What is in it |
| --- | --- |
| `appWeb/` | The website and web app |
| `appApple/` | The Apple apps |
| `appAndroid/` | The Android app |
| `tests/` | Automated checks. Many are "guards" that fail the build if a known mistake comes back |
| `tools/` | Build and data-preparation scripts |
| `wiki/` | The project wiki, kept inside this repository |
| `.claude/` | Claude's rules, plans and handoffs. Most of it is useful to Codex too |

## Read these first, in this order

1. **The newest `.claude/sessions/*-HANDOFF.md`** → its "Current state" section. It covers where the
   work has got to, what is waiting on the owner, and what comes next.
2. **`AGENTS.md`** (repo root) → writing in plain English, and how the work is run.
3. **`.claude/standing-directives.md`** → the owner's standing ways of working, in full.
4. **`.claude/CLAUDE.md`** → about fifty numbered architecture rules. Each one records a mistake this
   codebase has made before. Check it before touching the area a rule covers.
5. **`.OpenAI/MEMORY.md`** → short pitfalls.

## The channels

The code is deployed straight to the web host. There are no release tags.

- Pushing to `alpha` deploys the development site.
- `beta` and `main` deploy the test and live sites.

Work goes on **one** working branch, which is merged into `alpha` through a single pull request.

## Your usual role here

The main tool for this project is **Claude Code**. Codex is usually the **reviewer**: it reviews each
change, the fixes are made, and it reviews again until it finds nothing (standing-directives §13).
Codex is also the **first fallback** if Claude is unavailable (§14). If you take over, record that in
the handoff, and keep the handoff up to date as you go.
