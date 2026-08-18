# Alpha verification runbook — the "never once executed" list

**Proposal #2 (new-work pass, 2026-08-18).** A striking amount of shipped work is
believed-working from code review but has **never been run for real** on a
deployed environment or a physical device. This runbook converts that scattered
debt into ONE scripted pass. It is the deliverable; **execution is gated on the
feature branch reaching alpha** (owner-gated deploy) and, for several items, on
**two physical devices**.

> Nothing here can be run from the build sandbox — there is no deployed docroot
> and no second device. Each item names what unblocks it. Run top-to-bottom
> after the alpha deploy; tick each box; on a failure, reopen/annotate the named
> issue with what you saw (not "it broke").

## Preconditions

- [ ] The feature branch `claude/ilyrics-identity-work-model` is **merged to
      `alpha` and deployed** (SFTP deploy green). Confirm the footer build number
      on `https://<alpha-host>/` matches the latest commit.
- [ ] The pending **migrations** have been run on alpha via
      `/manage/setup-database` → *Apply all pending* (migrations are web-run, NOT
      auto-applied on deploy — CLAUDE.md rule #19). Confirm the pending counter
      reaches **0**.
- [ ] You have a **second physical device** (a phone on cellular/its own wifi)
      for the multi-device items, plus the alpha admin login.

---

## 1. Identity layer go-live (#1860) — the headline of this branch

Code state: mint-on-create + auto-link-on-save + dual-addressing landed
(`57ff0430`/`a898e471`/`4bb5d09b`), dormant-safe behind the `IlId` column gate.

- [ ] On alpha, create a new song in Editor2 → confirm a row appears in
      `tblSongs.IlId` shaped `IL<letter>0000000000` (via `/manage/schema-audit`
      or a quick admin query). **On fail → reopen #1893.**
- [ ] Open a song by its **IL id** address (`?action=song_detail` with the IL id)
      and by its **public SongId** — both resolve the same record. **On fail →
      #1860 go-live C.**
- [ ] Save a song carrying a CCLI or ISWC that matches an existing Work →
      confirm it auto-links (the "Part of work" panel appears). **On fail →
      #1894 / work-autolink.**
- [ ] **THEN and only then**, run the **#1872 tag-float-up backfill** (it is a
      run-once **destructive MOVE** on the shared DB — never before C4/C5 is live
      on every env; `confirm=1`-gated). Verify counts before/after. **This is the
      one item that WRITES data — do it deliberately, with a DB snapshot first.**

## 2. Offline downloads survive a deploy (#1597 — closed, device-verify pending)

Code state: all 7 RCs fixed in `service-worker.js.php` (SAVED_CACHE exemption,
media keep-list, page fallbacks, flat-URL eviction, bulk-done flag,
`storage.persist()`, the dead-wire dispatcher). The issue's own "Verification
reality" says end-to-end needs a real browser — **this is that.**

- [ ] On a **real phone**, install the PWA, download a whole songbook (e.g.
      Mission Praise), confirm the success toast.
- [ ] Open **one** other song, then re-check the download — it must **still be
      there** (RC1: the recency trim must not evict SAVED_CACHE). **On fail →
      reopen #1597 RC1.**
- [ ] Push any trivial change to alpha (bumps the SW version) → reopen the PWA →
      the downloaded **audio** must survive activation (RC2). **On fail → #1597
      RC2.**
- [ ] Go offline (airplane mode) → browse **home / songbooks / search**, not just
      a song page (RC3). **On fail → #1597 RC3.**
- [ ] Remove one download → the size display drops correctly (RC4). Toggle the
      "include audio offline" preference and confirm tile downloads honour it
      (RC7).

## 3. Service Mode live multi-device (#1339 — the remaining half)

Code state: both broadcaster front-ends landed; the projection **QR is landed**
(CueRCode `/qr.php`, rule #38 — the issue body predates this and is stale). Only
the **live multi-device verify** remains — it needs ≥2 devices on ONE channel.

- [ ] Migrations #1325/#1327/#1332/#1335 applied on alpha (part of Precondition 2).
- [ ] Start a session from `/manage/service-projection`; the rotating code shows
      and the QR renders (or the typed code shows if CueRCode isn't keyed — a
      **503 from `/qr.php` is expected until the CueRCode key is pasted** on
      `/manage/configuration`, rule #38).
- [ ] From the projection **song-nav console**, drive songs → a joined congregant
      device follows song **and section**. Include a song with a **custom
      arrangement** (verifies the arrangement-aware `componentIndex`).
- [ ] Connect a second operator via `/manage/service-lead` → it drives the SAME
      session; the keepalive keeps it fresh with no projection tab open.
- [ ] Join from **two** congregant devices on the **same channel** (⚠️ a
      desktop-on-dev + phone-on-www test ALWAYS fails — sessions are walled to
      their `Channel`, CLAUDE.md rule #26). Confirm per-token (NAT-safe) polling
      and clean end-of-service teardown. **On fail → annotate #1339.**

## 4. Streaming DB backup (#1771 — landed, never run on alpha)

Code state: the `MYSQLI_USE_RESULT` streaming fix is in `appWeb/.sql/backup.php`;
never executed on a real dataset.

- [ ] Run the backup on alpha against the real (~14k song) dataset → completes
      without exhausting memory, produces a restorable dump. **On fail → reopen
      #1771 with the memory/error output.**

## 5. IA reconcile live smoke (#1803)

- [ ] Confirm the issue's current state first (it may have moved). Then run the
      IA reconcile job on alpha and confirm it reports sane counts, no fatal.
      **On fail → annotate #1803.**

## 6. Visual / UX spot-checks (no second device needed)

- [ ] **#1862** Editor2 Metadata tab: the Composition-IDs **nested `<fieldset>`**
      renders correctly (valid HTML, but wanted an eyes-on check on alpha).
- [ ] **Editor2 on a phone** (#1845 follow-through): the tabbed shell, the
      arrangement editor, and save work on a narrow touch viewport.
- [ ] **#1710** (fixed this branch): sign in, open **Settings** → the language
      section reads "Your selection saves to your account and syncs…", NOT "Sign
      in to sync…". Sign out → it flips back.
- [ ] **#1699** (fixed this branch): set a per-setlist expiry, share it as a
      **live** link, let the expiry pass → the share page shows "no longer
      shared" (410 / empty card), and the owner's data is NOT deleted.

---

## After the pass

- Update each named issue with **evidence** (what you saw), close the ones that
  verify clean, reopen/annotate the ones that don't (CLAUDE.md standing task 2).
- The two-device items (#1339) and the destructive backfill (#1872) are the only
  ones that genuinely cannot be shortcut — everything else is a single-device or
  single-session check.
- Fold anything newly discovered into the #1878 sweep.
