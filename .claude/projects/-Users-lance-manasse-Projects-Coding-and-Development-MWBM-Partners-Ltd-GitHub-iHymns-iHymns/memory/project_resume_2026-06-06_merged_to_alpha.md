---
name: project_resume_2026-06-06_merged_to_alpha
description: CURRENT RESUME POINT (2026-06-06) — DB-direct epic + v0.770 program is MERGED & LIVE on alpha. Future work branches from alpha.
metadata:
  type: project
---

**CURRENT RESUME POINT.** ✅ The **DB-direct data-layer rewrite (epic #1010) + the v0.550→0.770 multi-format & lyrics-platform program** is **MERGED to `alpha` and deployed** (PR **#1160**, merge `586b2265`, auto-merged + SFTP-deployed, CI green). App version **v0.770.0**.

This was the long-running `claude/db-direct-data-layer` branch (88 commits, 253 files, +45k/−8.5k, 31 additive/dormant migrations). **Future work branches fresh from `alpha`** — that branch is done.

Verify-pass follow-ups also merged: **#1161** (W3C validity residuals — per-fragment validation found `autocomplete`-on-checkbox ×24, panel/badge roles), **#1162** (migration **OR-probe** drift fix for `tblSongLinkSuggestions.Signal` + the v0.770.0 version bump).

**Note on this repo's tracked memory snapshot:** the other files in this dir are a stale cross-device sync (v0.10.1-era). The canonical, live session memory is maintained outside the repo; a full refresh of this snapshot is `tools/sync-claude-session.sh`'s job.

**Authoritative detail:** `.claude/sessions/2026-06-06-HANDOFF.md` (post-merge state + the owner's open manual checks: run the NormalizedTitle migration directly + Apply-all for the re-pending Signal column) and `.claude/ProjectBrief.md` (the "Current Version" header).

**Owner manual checks still open:** migrations → zero-pending on `/manage/setup-database`; #448 reorder persistence; themed-503; dark/CVD/HC on the new UI; cross-device first-login merge. **Next code work:** issue-sweep #1159 (close-with-evidence the merged work), a11y residuals #1151/#1150, annotation backfill #1158, Wiki #408.
