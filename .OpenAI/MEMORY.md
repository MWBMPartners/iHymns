# iHymns — Codex memory

Short, lasting facts. Newest first within each section. Where this file and the newest handoff in
`.claude/sessions/` disagree, the handoff wins. Fix this file when that happens.

_Last updated: 2026-09-23._

## Working pitfalls

- **An empty review is not a clean review.** When Codex has hit its usage limit it can print nothing
  on its normal output at all. Check the error output, and check the review file actually has
  something in it, before treating the result as "no issues".
- **Codex was at its usage limit from 14 to 20 September 2026.** Reviews in that period were done by
  a stand-in reviewer. A catch-up Codex review of commit `f108b948` is still owed (issue #2123).
- **A "Closes #N" line in a pull request into `alpha` does not close the issue**, because `alpha` is
  not the default branch. Close the issue by hand.
- **Merging a pull request does not delete its branch** here. Delete it by hand, but only after
  `git diff origin/alpha origin/<branch>` prints nothing.
- **Before switching to `beta` or `main`**, check that branch does not track files that `alpha` has
  stopped tracking. Switching once deleted 1.6 GB of local files (#2108).

## Code facts that surprise people

- **Per-line song data (notes, chords, singing parts) follows a line's identity number, never its
  position** (rule #51 in `.claude/CLAUDE.md`). Carrying it across by position puts it on the wrong
  words, and nobody notices.
- **Song reads come straight from MySQL.** There is no whole-catalogue JSON file (rule #17).
- **A page fragment can never run an inline `<script>`.** The site's security policy silently blocks
  it (rule #30).
- **Version numbers:** the visible version sits in `appWeb/public_html/includes/infoAppVer.php`. The
  build number is added separately at deploy time. Pull request titles must start with a short
  label saying what kind of change it is (`feat:` for a new feature, `fix:` for a bug fix, and so on), because the prefix decides whether the version goes up (rule #46).
