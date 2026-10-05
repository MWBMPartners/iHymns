# Computer-wide rules (for every project on a computer, not just iHymns)

The owner asked (2026-09-23) for some rules to apply **in every project on the computer**, not only in
this repository. Claude Code reads those from `~/.claude/CLAUDE.md`, and Codex reads them from
`~/.codex/AGENTS.md`. Both files live on the computer itself, outside any repository.

This repository keeps the text so it can be installed on any computer, and reinstalled after a
change. To install it, run this from the repo root on the computer you want to set up:

```sh
bash tools/install-device-rules.sh
```

The script is safe to run more than once. It places the block below between two marker lines. On a
later run it replaces only what sits between those markers, and leaves everything else in the file
alone.

> ⚠️ Cloud sessions (Claude Code on the web) run in a temporary machine. Installing there lasts only
> for that session. The script needs to be run once on each **real** computer you work from.

Everything between the two marker lines below is what gets installed. Edit it here, then re-run the
script.

<!-- BEGIN DEVICE RULES -->
## Owner's computer-wide rules (installed from the iHymns repo — tools/install-device-rules.sh)

### Plain, everyday English
Explain things in plain, everyday English: in replies, progress reports, commit messages, comments,
issues and docs. Avoid technical jargon. It confuses even technically skilled readers. When a
technical term is genuinely needed, say in ordinary words what it means. More words are fine if they
make the meaning clearer.

### If an AI tool becomes unavailable: hand over, then switch back
This applies to **any** AI coding tool or its agents, whichever tools are in use.

- If the project's main AI tool stops working (it is down, out of tokens or usage credit, or at a
  usage limit), another suitable AI tool may take over, **if** it can do so without losing context
  or progress.
- That depends on the project's **handoff document** being kept up to the minute: what is done, what
  is in progress, what is next, and what decisions are waiting. Keep it current as you work, not only
  at the end.
- **Switch back to the main tool often**, as soon as it is available again. Do not stay on the
  fallback just because it works.
- **When the main tool is back, have it do a FULL review** of the work done on the fallback.
  Reviews that go across tools catch most differences in approach, but the full review is still owed.
- **Record every switch in the handoff:** which tool took over, when, what it did, and whether the
  full review on return has happened.

### Reviews go across tools
Where possible, have a **different** AI tool review the work from the one that wrote it (for example,
Claude writes it and Codex reviews it, or the other way round). Fix what the review finds and review
again, until a review finds nothing. An empty review output is not a clean review. Check the tool
actually ran.
### Polish check for anything people can see
Before calling work on a website or app done, and as a full pass at least once a month, check for
the details that make it look unfinished, and fix what you find:
- **Addresses and previews:** no preview or default hosts (vercel.app, netlify.app, localhost,
  example.com) in anything public. Each page has its own title, description, canonical URL, favicon
  and share image. Check the no-JavaScript view that link previews see.
- **Pages people skip:** a real 404 with a way back, loading, empty and error states, and messages
  that say what happened and what to do next.
- **Structure:** one main heading per page, no skipped heading levels, alt text on every image,
  and a sensible site map.
- **Leftovers:** no console errors or stray logs, no developer notes, placeholder text or test
  files reachable from the web, and no large libraries loaded but unused.
- **Every size:** phone, tablet, desktop and an odd landscape size, with very long text, empty
  data and error states. No sideways scrolling.
- **Consistency:** one set of colours, corner radii, fonts, button styles and icons.
- **Dead controls:** every button and link does something.

If the project can run an automatic check for any of this on every change, add one. In the report,
list what you did **not** check and why. A clean result you didn't actually look for is not a pass.
<!-- END DEVICE RULES -->
