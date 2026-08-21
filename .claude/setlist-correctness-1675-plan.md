# Set-list correctness cluster — locked implementation spec (#1675 + #1660 + #1662-reopened + #1802)

**Fable-5 design pass, 2026-08-21.** Wave 2 of `.claude/proposals-program-plan.md` (§2 item 2, §3 item 5).
Branch at design time: `claude/ilyrics-identity-work-model` @ `6b644461`. **No code changed by this pass.**

Four issues, one family of files, one design:

| Issue | Problem | Fix (locked) |
|---|---|---|
| #1675 / #1660 | The sync upsert is unconditional last-writer-wins — a stale device's `replace` push silently overwrites a collaborator's newer edit | Per-row conflict refusal: server row newer than the client's `since` watermark ⇒ keep the server row, report `{id, reason:'newer_on_server'}`; client absorbs server-wins + toasts |
| #1662 (reopened) | `setlistCollabSanitiseSongs()` silently `array_slice`s to 200 songs on all THREE write paths | 413 **rejection before write** (`reason:'too_many_songs'`), one shared cap function, three call sites; the slice survives ONLY for protocol-1 sync clients (byte-compat mandate) |
| #1802 | The #1791 token-edit surface has reorder/remove but no way to ADD a song | One shared add-a-song combobox (the `request-a-song.js` public-picker shape, rule #43) wired into BOTH edit surfaces, writing through the existing push paths |
| (load-bearing) | Native apps send no `since`, no `deleted` | Both new guards are protocol-gated so a protocol-1 client's request/response cycle is **byte-identical** |

---

## 1. Verified current state (file:line, all under `appWeb/public_html/`)

### 1a. The unconditional upsert (#1675/#1660)

- `api.php:3579` `case 'user_setlists_sync'`. The upsert statements at `:3758-3776` (and the
  plan-carrying variants `:3804-3828`) end in
  `ON DUPLICATE KEY UPDATE Name=VALUES(Name), SongsJson=VALUES(SongsJson), UpdatedAt=VALUES(UpdatedAt) [, ExpiresAt, SlotsJson]`
  — **no version/watermark compare of any kind**. The doc-comment at `:3539-3542` already admits it
  ("Existing setlists are OVERWRITTEN by the payload … unconditional — there is no 'local version is
  newer' comparison"), which is the truthful half #1660 asked for; the code half is this plan.
- The `since` watermark (`:3662` parse → `userSyncDeletableIds()` at `:3921-3935`,
  `includes/user_sync.php:257-290`) protects **deletion only** (refusal 3, strict `<` at `:283`).
  Content overwrites never consult it.
- The loop runs in **'merge' mode too** (`:3654`, `:3832-3907`): the first-login reconcile
  (`user-auth.js:2140`, mode 'merge') upserts every local list over the server rows — so a device
  that comes back online after a week clobbers newer server content **at sign-in**, before any
  'replace' push. The conflict guard must therefore live in the upsert loop, not in a mode branch.
- **Clock frames are mixed** (the trap that would brick a naive timestamp compare):
  - the sync upsert stamps `UpdatedAt` from **PHP UTC** (`$now = gmdate('Y-m-d H:i:s')`, `:3743`);
  - the collab/token write core stamps `UpdatedAt = UTC_TIMESTAMP()`
    (`includes/setlist_collab.php:447,455`) — also UTC frame;
  - the watermark handed to clients (`syncedAt`, `:3956`) is `userSyncNow()` = **`SELECT NOW()`**,
    the *session-time-zone* frame (`user_sync.php:347-351`); its own doc-block (`:319-338`)
    documents exactly this skew and says aligning the upsert onto the DB clock "belongs in its own
    change". This plan is that change (§3.3) — without it, on a DB session behind UTC every row
    would read *newer* than a watermark minted the same instant and the conflict guard would refuse
    every push forever.
  - `tblUserSetlists.UpdatedAt` is `TIMESTAMP … ON UPDATE CURRENT_TIMESTAMP` (schema.sql:1436+)
    but the app always supplies an explicit value, which suppresses ON UPDATE — the app is the clock.
- The client half: `user-auth.js:642-739` (`syncSetlists` — sends `since` `:674-676`, `deleted`
  unconditionally `:677-684`, handles 413 by flag+toast `:694-702`), `_absorbSetlistSync` `:590-605`
  (prune tombstones → `_unionSetlists` `:2165-2170` where **current-local wins by id** → advance
  watermark). Note: current-local-wins means a naive server-side conflict refusal would be
  *resurrected locally* on absorb and re-pushed forever — the client absorb MUST special-case
  conflicted ids (§3.5).

### 1b. The 200-song silent amputation (#1662)

- `includes/setlist_collab.php:355-384` — `setlistCollabSanitiseSongs($songs, int $max = 200)`,
  `array_slice($songs, 0, $max)` at `:361`. Silent; no `songsTruncated` flag exists anywhere.
- Three write paths call it (verified by tree grep — the only call sites):
  1. `api.php:3860` — `user_setlists_sync` (per-list, inside the upsert loop);
  2. `api.php:11803` — `setlist_collab_update` (account-collaborator edit);
  3. `api.php:11971` — `setlist_token_update` (anonymous edit-link).
- The reject-never-slice precedents already in this handler: whole-body 4 MiB → 413 pre-decode
  (`:3600-3606`, #1661), plan-slot cap → 413 pre-write (`:3629-3637`, #1671 F4 — whose comment
  names "their 201st song in #1662" as the same shape). `tblUserSetlists.SongsJson` is
  **MEDIUMTEXT** (schema.sql), so 200 is a product cap, not a storage necessity.

### 1c. No add-a-song on the shared edit surface (#1802)

- `includes/pages/setlist-shared.php` is a 173-line shell: songs container `:146`, edit status
  `:152`; **no add control**. `js/modules/setlist.js:3414-3506` (token surface) offers
  reorder/remove only via `sharedSetlistRowsHtml()` (`:73-102`) +
  `bindSharedSetlistRowControls()` (`:120-151`) + `pushTokenEdit()` (`:3455-3503`) →
  `setlist_token_update`. The account-collab detail surface (`renderSharedSetListDetail`, push at
  `:994-1033`) has the same gap.
- All dependencies exist: the write path (`setlist_token_update` → `sharedSetlistUpdate()` /
  `setlistCollabPerformUpdate()`, rule #40), the `401 {reason:'signin_required'}` client branch
  (`:3470-3482`), and the public-surface picker doctrine — `js/modules/request-a-song.js:295-336`
  establishes that `/manage`-only `place-search.js` is **not** loaded publicly; a public typeahead
  is built from the same two blocks: `combobox-a11y.js` + `apiFetch` against an existing public
  read. The per-keystroke song source exists: `api.php:921` `?action=search&q&songbook&limit&lyrics`
  → `{results:[{id,title,songbook,number,…}], total, hasMore}` (rate-limited 120/min, fail-open —
  the same endpoint `search.js` already hits debounced per keystroke).
- `songsDetailed` (the object shape the edit surface is seeded from) comes from
  `includes/SharedSetlist.php:~561-576` — id/title/songbook/number(+arrangement), i.e. exactly the
  entry shape `setlistCollabSanitiseSongs()` stores and `addSong()` (`setlist.js:456-479`) builds.

### 1d. Legacy-client surface

- Protocol-2 marker = **presence of the `deleted` key**, even `[]`
  (`userSyncExplicitProtocol()`, `user_sync.php:527-531`). The native Apple/Android apps send
  neither `deleted` nor `since` (stated at `api.php:3563-3565`, `user_sync.php:242-247`).
- Adding **new** response keys is established as safe; removing/annulling existing ones is not
  (`api.php:4029-4033` keeps `cap: null` for exactly this reason).
- `setlist_collab_update` / `setlist_token_update` are #1638/#1791 web-only features — no native
  client calls them; they need no protocol gating.

---

## 2. Design principles (inherited, not re-argued)

- **Err toward keeping** (#1649): when in doubt, the server keeps what it has and says so. A
  refusal the user can see beats a success that ate data.
- **Reject, never slice** (#1661): an over-limit payload is a loud 413 with nothing changed.
- **Status + machine-readable `reason` is the contract** (rule #35): clients branch on
  `res.status` and a `reason` key, never on prose.
- **Write through the ONE core, read back what the server stored** (rule #40).
- **One shared helper per decision** (rule #22): cap in one function, conflict predicate in one
  pure function, picker in one mount helper.
- **Byte-identical for protocol-1** (this cluster's own requirement 4): every new behaviour is
  gated so a no-`since`, no-`deleted` request takes exactly today's code path.

---

## 3. Locked design

### 3.1 Vocabulary — the machine-readable `reason` values (rule #20 discipline)

One flat wire vocabulary for this family, emitted next to `error` (which stays human prose):

| Where | HTTP | `reason` | Extra keys |
|---|---|---|---|
| whole-body ceiling (existing, `:3600`) | 413 | `body_too_large` *(added)* | `maxBytes` (existing) |
| plan-slot cap (existing, `:3629`) | 413 | `too_many_slots` *(added)* | `maxSlots` (existing) |
| songs cap (NEW, all three paths) | 413 | `too_many_songs` | `maxSongs`, `setlistId` (sync path only) |
| per-row conflict (NEW, sync response) | 200 | `newer_on_server` (per entry in `conflicts[]`) | `serverUpdatedAt` |
| edit-audience (existing, `:11958`) | 401 | `signin_required` (unchanged) | — |

Adding `reason` to the two existing 413s is additive (natives ignore unknown keys — the
`cap: null` precedent) and gives every 413 in the family one branchable shape. The conflict is
**HTTP 200**: the request as a whole succeeded and was partially applied; a 4xx would falsely claim
nothing happened. The per-row outcome is data, carried by a structured key — presence of
`conflicts[]` entries is what the client branches on.

### 3.2 The songs cap — 413 rejection before write (#1662)

**Cap constant — one function** in `includes/setlist_collab.php`, next to the sanitiser:

```php
function setlistCollabMaxSongs(): int { return 200; }
```

**The sanitiser loses its slice.** `setlistCollabSanitiseSongs($songs): array` — the `$max`
parameter and the `array_slice` at `:361` are removed; the function becomes pure shape-sanitising
(unbounded — the whole-body 4 MiB ceiling already bounds hostile input before it, and the cap
guard below bounds it per-list). This is the #1671 posture: "the module deliberately owns no
array_slice()".

**Call site 1 — `user_setlists_sync`** (protocol-gated). A new pre-write pass sits beside the
plan-slot check at `:3629-3637` — i.e. BEFORE the expire/delete/read/upsert sequence, so an
over-cap payload leaves every stored row untouched:

```php
/* #1662 — THE SONGS CAP IS A REJECTION, NEVER A TRUNCATION (protocol 2).
   Raw count, before sanitising, mirroring the whole-body ceiling: the work is
   bounded before it is done, and the cap is on what you SENT. Protocol-1
   clients (the native apps — no `deleted` key) keep the historical silent
   slice below, byte-identical, until they adopt protocol 2. */
if ($clientProtocol2) {
    foreach ($localLists as $songsCheck) {
        if (is_array($songsCheck) && is_array($songsCheck['songs'] ?? null)
            && count($songsCheck['songs']) > setlistCollabMaxSongs()) {
            sendJson([
                'error'     => 'A set list has too many songs. Nothing was changed.',
                'reason'    => 'too_many_songs',
                'maxSongs'  => setlistCollabMaxSongs(),
                'setlistId' => userSyncSanitiseId($songsCheck['id'] ?? ''),
            ], 413);
            break 2;
        }
    }
}
```

Note the gate is `$clientProtocol2` (the client's *claim*, unqualified by `$tombstonesReady` — the
413 needs no schema), and the count is the **raw** array (pre-sanitise), matching the raw-body
ceiling's "bound the work before doing it" doctrine and the legacy slice's raw-first order.

In the upsert loop, `:3860` becomes:

```php
/* legacy-protocol1-slice (#1662) — the ONLY array_slice left on a songs
   array anywhere. Protocol-1 (native) clients keep today's silent
   truncation byte-for-byte; protocol-2 clients were already 413'd above,
   so for them this branch is structurally unreachable over-cap. */
$songsIn = $list['songs'] ?? null;
if (!$clientProtocol2 && is_array($songsIn)) {
    $songsIn = array_slice($songsIn, 0, setlistCollabMaxSongs());
}
$cleanSongs = setlistCollabSanitiseSongs($songsIn);
```

(Slice-before-sanitise order preserved deliberately — sanitise-then-slice would store different
bytes than today when malformed entries precede index 200.)

**Call sites 2 + 3 — `setlist_collab_update` (`:11803`) and `setlist_token_update` (`:11971`)** —
unconditional (web-only surfaces, no native caller). Immediately after the `songs (array)
required` 400 check in each:

```php
if (count($body['songs']) > setlistCollabMaxSongs()) {
    sendJson([
        'error'    => 'This set list has too many songs. Nothing was changed.',
        'reason'   => 'too_many_songs',
        'maxSongs' => setlistCollabMaxSongs(),
    ], 413);
    break;
}
```

**Client handling (all three consumers, branch on status + `reason`, never prose):**

- `user-auth.js:694-702` — split the existing 413 branch on `data.reason`:
  `'too_many_songs'` → toast naming the list (resolve the name from the local cache by
  `data.setlistId`) and `data.maxSongs`; anything else (incl. `body_too_large` / absent) → the
  existing "too large to sync" toast. Both latched once per session as today. Nothing local is
  changed (server changed nothing) — the local over-cap list simply stays local, loudly.
- `setlist.js` collab-detail `push()` (`:994-1033`) — on `!res.ok`, **revert the staged `songs`
  array to the last server-confirmed copy** before painting the error (the function's own comment
  already promises this; the code never did it — a staged over-cap array would otherwise re-push
  the refused state on every subsequent tap). Mirror the token surface's `lastGoodSongs` pattern
  (`:3429,:3487-3489`). On 413 + `too_many_songs`, the message uses `res.data.maxSongs`.
- `setlist.js` `pushTokenEdit()` (`:3455-3503`) — already reverts on any `!res.ok`; add the
  413-specific message from `res.data.maxSongs`. No hardcoded client-side cap constant anywhere —
  the number reaches the client only via the 413 body (rule #35: the response is the mechanism).

**Cap value decision: keep 200** (owner-changeable in one line). Rationale: a real service runs
~15–25 songs; 200 is already an order of magnitude of headroom; the #1662 report is about the
*silent* behaviour, not the number; MEDIUMTEXT imposes no storage force; and raising it now would
change what legacy natives slice at, silently forking stored data across client generations.

### 3.3 One clock — frame alignment (prerequisite for §3.4)

The conflict compare is `serverRow.UpdatedAt > since` on 19-char strings. Both sides MUST be
minted by the same clock or the guard is wrong by the DB session's UTC offset (see §1a; on a
behind-UTC session it would refuse **every** push — a bricked sync). Two one-line changes put every
writer in the watermark's own `NOW()` frame:

1. `api.php:3743`: `$now = gmdate('Y-m-d H:i:s');` → `$now = userSyncNow($db);`
   (the change `user_sync.php:319-338`'s caveat explicitly reserved for "its own change" — this
   also tightens #1649 guard 3, whose skew caveat that doc-block documents; update the doc-block).
2. `includes/setlist_collab.php:447,455`: `UpdatedAt = UTC_TIMESTAMP()` → `UpdatedAt = NOW()`
   in `setlistCollabPerformUpdate()` — collab/token edits then stamp the same frame, so a
   collaborator's fresh edit is comparable against an owner-device watermark (the headline #1675
   scenario depends on this).

Transition safety (existing rows carry UTC-frame stamps): on a UTC-session install (frames already
equal) this is a no-op. On a session **ahead** of UTC, old stamps read older → fewer conflicts →
degrade to today's LWW — safe. On a session **behind** UTC, old stamps read up to |offset| newer —
which is exactly what the future-stamp clamp in §3.4 exists to neutralise. `ExpiresAt` handling is
untouched (it deliberately stays on the UTC clock pair, `userSyncUtcNow()` — different comparison,
different frame, per `user_sync.php:656-668`).

`CreatedAt` inherits the same `$now` default it does today; no contract change.

### 3.4 The conflict-safe upsert (#1675/#1660)

**Gate:** `$since !== null` — any client that sent a valid watermark gets the guard, in **both**
'merge' and 'replace' modes (the merge-mode login reconcile is the worst stale-overwrite window,
§1a). Natives send no `since` → guard structurally inert → byte-identical (requirement 4).

**Two pure helpers** in `includes/user_sync.php` (function_exists-guarded, testable with no DB,
mirroring the file's existing pattern):

```php
/* Is this server row too new for this client to overwrite?
   Strict `>` — a row stamped in the SAME second as the watermark is
   overwritable. Deliberately the opposite boundary from userSyncDeletableIds()'s
   `<` (which keeps on ambiguity): a deletion refused on the boundary costs a
   repeat; an OVERWRITE refused on the boundary would make a client conflict
   with its own previous push (row ts == syncedAt is the normal same-second
   outcome of one request), a self-inflicted refusal loop. The residual is a
   one-second LWW window, vs. today's permanent one.
   FUTURE-STAMP CLAMP: a row stamped more than $slack (300 s) past the DB's own
   now cannot be a real concurrent write in this frame — it is a frame-poisoned
   stamp (a pre-§3.3 UTC-frame row read on a behind-UTC session). Trusting the
   write there degrades to today's LWW instead of refusing pushes for hours. */
function userSyncRowNewerThanWatermark(?string $since, string $rowTs, string $dbNow, int $slackSeconds = 300): bool
{
    if ($since === null) return false;
    $ts = substr($rowTs, 0, 19);
    if (!($ts > $since)) return false;
    $ceiling = gmdate('Y-m-d H:i:s', strtotime(substr($dbNow, 0, 19)) + $slackSeconds);
    /* NOTE: the ceiling is derived arithmetically from $dbNow (a same-frame
       string), so the frame cancels; gmdate here is only +N-seconds string
       maths, not a second clock. */
    return $ts <= $ceiling;
}

/* Content-identity: is the incoming row a byte-level no-op against the stored
   one? Compared field-by-field so "not provided" keys (plan) mean "ignore",
   matching the write path's preserve semantics. SongsJson byte-compares
   soundly (MEDIUMTEXT stores exactly the bytes our own two funnels encoded);
   SlotsJson is a JSON column MySQL re-normalises, so plans compare through
   the canonical encode∘decode fold, never bytes. */
function userSyncSetlistRowUnchanged(
    array $srv, string $name, string $songsJson,
    bool $expiryReady, ?string $expiresAt,
    bool $planProvided, ?string $planJson
): bool
```

(`userSyncSetlistRowUnchanged` returns false on any doubt — a false "changed" merely writes or
conservatively conflicts; a false "unchanged" would silently drop an edit, so doubt fails toward
"changed". Plan comparison: `setlistTemplateEncodePlan(setlistTemplateDecodePlan($srv['SlotsJson'])) === $planJson` —
both sides through the ONE canonicaliser. This canonical-fold is load-bearing: a byte-compare of
the JSON column would read every planned list as "changed" on every push, bump its `UpdatedAt`,
and manufacture a permanent spurious-conflict loop between two active devices.)

**The upsert loop** (`:3832-3907`) gains a three-way branch per row. Before the loop, index the
already-fetched snapshot (`$serverRows`, `:3727-3734` — zero extra queries) and mint one
`$dbNowForClamp = userSyncNow($db)` (reuse `$now` from §3.3 — same value, same read):

```php
$serverMap = [];
foreach ($serverRows as $r) { $serverMap[(string)$r['SetlistId']] = $r; }
$conflicts = [];
```

Inside the loop, after the tombstone skip and the `$name`/`$cleanSongs`/`$songsJson`/`$expiresAt`
computation, before the execute:

```php
$srv = $serverMap[$setlistId] ?? null;
if ($since !== null && $srv !== null) {
    /* (a) NO-OP SKIP — identical content writes nothing and bumps nothing.
       Load-bearing twice over: it stops every routine push refreshing every
       row's UpdatedAt (which would make ALL rows read "newer" to every other
       device and turn the guard below into a conflict factory), and it makes
       a post-conflict converged push settle silently. */
    if (userSyncSetlistRowUnchanged($srv, $name, $songsJson,
            $expiryReady, $expiresAt, $planStatement, $planJson ?? null)) {
        $payloadIds[] = $setlistId;
        continue;
    }
    /* (b) CONFLICT — the row changed server-side after this client's last
       absorb, so this push would overwrite work the client has never seen.
       Keep the server row (#1649 err-toward-keeping), report the refusal.
       The response's merged list already carries the authoritative row, so
       the entry is a status, not a data carrier. */
    if (userSyncRowNewerThanWatermark($since, (string)$srv['UpdatedAt'], $now)) {
        $conflicts[] = [
            'id'              => $setlistId,
            'reason'          => 'newer_on_server',
            'serverUpdatedAt' => substr((string)$srv['UpdatedAt'], 0, 19),
        ];
        $payloadIds[] = $setlistId;
        continue;
    }
}
/* (c) fall through to the EXISTING upsert statements, untouched. */
```

Both skipped and conflicted rows still enter `$payloadIds` — the client clearly holds them, so the
deletion pass must treat them as present (belt; refusal 3 would keep the conflicted row anyway).
A refused row is refused **whole**: name, songs, expiry and plan are all withheld together — never
a partial write.

Race window: the decision reads the `$serverRows` snapshot taken earlier in the same request; two
same-user requests racing within those milliseconds can still LWW each other. That is the same
read-then-act window `userSyncDeletableIds()` already accepts, the loser's damage equals today's
behaviour for exactly one row, and the atomic alternative (a `WHERE UpdatedAt <= ?` guard on a
split UPDATE/keep-existing-INSERT pair) quadruples the statement matrix for a same-user
millisecond race. Accepted; documented in §A.2; escalation path named there.

**Response + audit** (`:4001-4036`):

```php
'conflicts' => $conflicts,   /* ALWAYS present, [] when none — additive key,
                                natives ignore it (the cap:null precedent) */
```

and the audit block gains `'conflicts' => count($conflicts)` with `count($conflicts) > 0` added to
its trigger condition — a refused overwrite is precisely what a curator investigating "my edit
vanished / my edit won't save" needs to see.

**Doc-comment**: the block at `:3535-3577` is rewritten to describe the conditional upsert (this is
the code-matches-comment half of #1660; the comment's #1649 confession becomes history, cited).

### 3.5 Client consumption of the conflict signal

`user-auth.js`:

1. `syncSetlists()` returns `conflicts: Array.isArray(data.conflicts) ? data.conflicts : []`
   alongside the existing keys.
2. `_absorbSetlistSync()` — **server wins for conflicted ids.** The current union puts local on
   top (`:2165-2170`, correct for in-flight edits); left alone it would resurrect the refused edit
   locally and re-push it into a permanent conflict loop. Fix:

```js
const conflictIds = new Set((res.conflicts || []).map(c => c && c.id).filter(Boolean));
const localSide = conflictIds.size
    ? pruned.filter(l => l && !conflictIds.has(l.id))
    : pruned;
const final = this._unionSetlists(localSide, res.setlists);
```

3. Notify — one toast per absorb (not per row), naming the lists (titles read from
   `res.setlists`): *"'{name}' was updated on another device — showing the newer version. Your
   last change to it was not applied."* (`warning`, ~8 s). No auto-retry and no auto-merge: an
   automatic re-push of the local edit after absorbing would be LWW with extra steps, defeating
   the guard; a row-level three-way merge has no base to merge from. The user re-applies the edit;
   the next push finds `ts <= since` (watermark advanced past the conflict) and succeeds — or the
   absorbed copy already satisfies them. Self-healing by construction: absorb ⇒ local == server ⇒
   next push hits the no-op skip.

Convergence proof for the loop-free claim: conflict ⇒ absorb takes server copy + watermark
`syncedAt` (minted after the refused write attempt, so `>` the row's stamp) ⇒ next push:
content-identical ⇒ branch (a) skip ⇒ no write, no conflict, no bump. ∎

### 3.6 Add-a-song on the shared edit surfaces (#1802)

**One mount helper, two consumers** (module scope in `setlist.js`, beside the row-template pair it
completes):

```js
/* mountSetlistAddSongPicker(hostEl, { getSongs, onPick }) → teardown fn */
```

- Built the `request-a-song.js` way (§1c): `combobox-a11y.js` for keyboard/ARIA + `apiFetch` (rule
  #31) against the EXISTING public `?action=search&q=…&limit=8&lyrics=0` (debounced ~250 ms,
  min 2 chars; each result rendered as title + songbook/number meta). **Never** `place-search.js`
  (manage-only bundle) and never a new endpoint. Find-or-create does not apply — a set-list entry
  *references* songs, it never mints one, so this is the pick-only end of rule #43 (the
  `pickMode:'value'` analogue: selection commits, nothing is created).
- On pick: duplicate guard against `getSongs()` (same `s.id === song.id` rule as `addSong()`
  `:462`; duplicate → info toast, no push), else append the four-field entry
  `{id, title, songbook, number}` (the exact `setlistCollabSanitiseSongs()` shape) and call
  `onPick()`.
- **No client-side cap constant.** The server's 413 is the mechanism (rule #35); the existing
  revert-on-refusal in both push paths (§3.2) restores the staged copy and the toast carries
  `maxSongs` from the response body.

**Consumer 1 — the token surface** (`initSharedSetListPage` edit branch, `:3420+`): un-hide a new
shell block and mount with `getSongs: () => editableSongs`,
`onPick: () => { syncHeaderFromSongs(); renderEditRows(); pushTokenEdit(); }` — the exact
`onMutate` flow reorder/remove already uses, so the add rides the same
sanitise → audience-gate → ONE-write-core path (`setlist_token_update` →
`setlistCollabPerformUpdate()`, rule #40 — nothing minted, audience re-resolved per write, the
`401 signin_required` branch at `:3470` already downgrades the whole surface, add control
included, to the sign-in prompt).

**Consumer 2 — the collab-detail surface** (`renderSharedSetListDetail`): render a host div in its
`canEdit` branch and mount with `onPick` = its existing `onMutate`
(`shared.songs = songs; re-render; push()`), pushing through `setlist_collab_update`.

**Shell change** (`includes/pages/setlist-shared.php`, after `#shared-setlist-edit-status`):

```html
<!-- #1802 — add-a-song host for the token-edit surface. Hidden until JS
     confirms canWrite; static markup only (rule #30 — no inline script;
     the module wires it from initSharedSetListPage). -->
<div id="shared-setlist-add-song" class="mt-3 d-none">
    <label for="shared-setlist-add-input" class="form-label small text-muted">Add a song</label>
    <input type="text" id="shared-setlist-add-input" class="form-control"
           autocomplete="off" placeholder="Search by title or number…">
</div>
```

(`page=setlist-shared` is not in `$_cacheablePages` — verified `api.php:608-621` — but the block
is static-markup-only regardless, so the rule #30 fragment guard stays green either way.)

**Read-back tightening (rule #40), same commit:** on a successful push, BOTH surfaces adopt
`res.data.songs` (the server's sanitised, stored truth — titles trimmed to 300, songbook to 20,
malformed entries dropped) into the staged copy / `lastGoodSongs` instead of assuming the local
array was stored verbatim. Today the token surface snapshots the *local* array (`:3495`) — a
sanitiser-trimmed field would silently diverge until the next full load.

---

## 4. Protocol gating matrix (the byte-identical proof obligations)

| Client sends | Cap 413 (sync) | Legacy slice | Conflict guard | `conflicts` key | Tombstones/absence rules |
|---|---|---|---|---|---|
| neither `since` nor `deleted` (native, protocol 1) | never | **yes — byte-identical** | never (`$since === null`) | emitted `[]` (additive, ignored) | unchanged (#1649/#1661 exactly as today) |
| `since` only (hypothetical 1.5) | never (`!$clientProtocol2`) | yes | **yes** | populated when refused | #1649 watermark, as today |
| `deleted` (+`since`) — the web PWA | **yes** | no (unreachable over-cap) | yes (when `since` present) | populated | protocol 2, as today |

Proof obligations carried into the tests (§6): a protocol-1 request's handling path executes the
same statements with the same bound values as today (the only observable delta anywhere for
protocol-1 is §3.3's clock source for `$now`, which on a UTC-session DB is the same value; on a
skewed one the delta is the very skew the #1649 doc-block already declared bounded-safe).
`setlist_collab_update`/`setlist_token_update` have no native callers, so their unconditional 413
carries no compatibility obligation.

---

## 5. §A — Adversarial analysis

**A.1 Two devices editing the same row inside one watermark second.** `syncedAt` is minted after
the writes, so a device's own rows routinely stamp the same second as its watermark. Strict `>`
means the guard does not fire on equality ⇒ no self-conflict ⇒ liveness; the cost is a one-second
window in which a genuinely concurrent third-party write can still be overwritten (LWW). Refusing
on equality instead would make every second consecutive push from ONE device conflict with itself
— a self-DoS. The window is the floor timestamp resolution imposes without a schema change; the
real fix for it is a per-row `RowVersion` counter (see A.2's escalation).

**A.2 Two requests racing inside one request's read-act window.** The conflict decision reads the
`$serverRows` snapshot; a same-user concurrent request landing between snapshot and execute is
invisible to it — the second writer LWWs the first for that row (≤ ms window, same account only,
identical to the window `userSyncDeletableIds()` already accepts). Damage bound = today's
behaviour for one row. **Escalation path if this ever bites in practice** (recorded so nobody
re-designs from scratch): add `RowVersion INT` bumped on every write + a `WHERE RowVersion = ?`
compare-and-swap, client echoing versions — a one-pass #20-style migration, deliberately NOT built
now because the second-resolution watermark already covers the cross-device (seconds-to-days)
window that is the actual reported failure.

**A.3 Delete-vs-edit race.** Device B deletes list X (tombstone, permanent); device A pushes an
edit to X. Whichever order the server sees: tombstone-first ⇒ A's upsert hits the anti-resurrection
skip (`:3848-3851`) and the edit is refused (counted in `resurrectionsRefused`); edit-first ⇒ B's
explicit delete then removes it and the tombstone propagates. **Delete deterministically wins** —
the #1661 doctrine, unchanged by this design (the conflict guard runs after the tombstone skip and
never resurrects). The edit's loss is visible: A's next absorb prunes X locally.

**A.4 A legacy client racing a v2 client.** (i) A native's whole-list push carries no `since` ⇒ no
guard ⇒ it can still LWW-overwrite a newer row — **the residual #1675 exposure is exactly the
legacy writer**, closable only by the native apps adopting protocol 2 (issue note, §7). (ii) More
subtly: pre-design, every native sync also *bumped every row's `UpdatedAt`* (unconditional
`UpdatedAt=VALUES(...)` with identical content), which would make the web device's next real edit
read `ts > since` and spuriously conflict. Branch (a)'s no-op skip removes the bump for protocol-2
pushes but a native's unconditional loop still bumps; when that interleaves between a web absorb
and a web edit-push, the web edit is refused, absorbed server-wins (content = what web already
had), toasted, and must be re-applied — annoying, loud, bounded to native-sync frequency, and in
the #1649 direction ("a change that needs repeating is an annoyance; one that silently evaporates
is the bug"). Documented in the toast copy's favour: the message says the change was not applied.

**A.5 The add-song hitting the cap.** Optimistic append → push → 413 `too_many_songs` → staged
copy reverted to `lastGoodSongs`, toast with `maxSongs`. The staged array can therefore never
persist an over-cap state that re-pushes forever (the revert is the same machinery any refusal
uses). Sync path: cap checked before ANY write, so a 413 leaves all rows and tombstones untouched
— and because it precedes the expire step too, not even a lazy expiry runs on a rejected request
(observable-change-free rejection).

**A.6 Frame-poisoned timestamps (the §3.3 transition).** Behind-UTC session + pre-transition rows
⇒ stamps read up to |offset| in the future ⇒ `> since` true spuriously; the future-stamp clamp
(`ts <= dbNow + 300 s`) reads any stamp materially past the server's own now as untrustworthy and
lets the write through (⇒ today's LWW, never a refusal loop). The 300 s slack tolerates ordinary
replication/read lag while being far under any real TZ offset (≥ 1800 s). Ahead-of-UTC sessions
degrade the other way (fewer conflicts) — also to today's behaviour. Either way the failure mode
of a skewed install is "the new guard under-fires", never "sync bricks".

**A.7 Silent-data-loss checklist for the new code itself** (the class that punished #1649):
- A conflict refusal is whole-row — no partial name/songs/expiry/plan write (single `continue`).
- `conflicts` entries carry no content, so a truncated/lost entry cannot corrupt data — worst case
  the client keeps a stale local row until its next push conflicts again (loud, convergent).
- The no-op skip can never eat an edit: `userSyncSetlistRowUnchanged` fails toward "changed" on
  any doubt (missing gated columns, decode failure, canonicalisation mismatch).
- The absorb's conflicted-id filter removes local rows ONLY when the server response contains a
  replacement for that id by construction (a conflict implies the server row exists and is in the
  merged read; if a pathological response carried a conflict id with no matching row, the filter
  would drop the local copy — so the client filter additionally requires the id to be present in
  `res.setlists`; cheap belt, spec'd into C3).
- Plan-preserve semantics unchanged: a conflicted or skipped row never reaches the plan statement,
  and an applied row uses the existing presence-of-key logic verbatim.
- The legacy slice cannot silently widen: it is gated on `!$clientProtocol2` — a client that ever
  sends `deleted` can never be sliced again (monotone per-request, no stored state to corrupt).

**A.8 What would make each fix wrong.** Cap: enforcing it on protocol-1 (native data loss becomes
native sync breakage — explicitly forbidden); counting sanitised instead of raw entries (order-
dependent acceptance). Conflict: comparing across clock frames without §3.3+clamp (bricked sync on
behind-UTC installs); refusing on the equal-second boundary (self-DoS); leaving `_unionSetlists`
current-wins for conflicted ids (permanent conflict loop); byte-comparing the JSON `SlotsJson`
column (spurious-conflict factory for planned lists — A.4's loop with no native involved).
Add-song: a client-side hardcoded 200 (rule #35 drift); loading `place-search.js` publicly;
minting anything (rule #40); a second row template.

---

## 6. CI guards (tree-derived, mutation-proven — rule #34)

Extend the existing family files where they exist; every guard's first run must be proven able to
fail (break → red → restore, recorded in each commit's verification).

1. **`tests/php/test-setlist-collab.php` (extend) — the cap is a rejection, not a slice.**
   - Functional: `setlistCollabSanitiseSongs()` with 250 valid entries returns 250 (no slice);
     `setlistCollabMaxSongs()` === 200.
   - Structural, tree-derived: enumerate ALL `setlistCollabSanitiseSongs(` call sites by grepping
     `api.php` + `includes/` (never a typed list of three); assert each call site's enclosing
     handler contains a `setlistCollabMaxSongs()` + `413` + `'too_many_songs'` guard textually
     BEFORE the call; assert `setlist_collab.php` contains zero `array_slice`; assert exactly ONE
     `array_slice` over a songs array exists in `api.php` and it sits inside a
     `!$clientProtocol2` branch carrying the `legacy-protocol1-slice` marker.
   - Mutation proof: re-add a slice to the sanitiser → red; delete one call-site guard → red.
2. **`tests/php/test-user-sync-guard.php` (extend) — the conflict predicate truth table.**
   - CALL `userSyncRowNewerThanWatermark()`: null since → false; older → false; equal-second →
     false; newer-within-clamp → true; newer-past-clamp (future stamp) → false.
   - CALL `userSyncSetlistRowUnchanged()`: identical → true; each field varied alone → false;
     plan canonical-equivalence (re-ordered/normalised JSON) → true; absent plan key ignored;
     un-gated columns ignored.
   - Structural: `api.php`'s sync case contains the `newer_on_server` emit, the `'conflicts' =>`
     response key, and `$payloadIds[] = $setlistId;` inside BOTH the skip and conflict branches
     (mutation: remove one → red).
3. **`tests/test-setlist-collab-client.js` / `test-setlist-share-client.js` (extend) — clients
   branch on status, never prose.**
   - Derive the setlist-family client files from the tree (`js/modules/*setlist*`, `user-auth.js`);
     assert the 413 handling branches on `.status === 413` and reads `.reason` / `.maxSongs`;
     assert NO regex/`includes(` match against server error sentences in the new branches;
     assert `user-auth.js` filters conflicted ids out of the local side before `_unionSetlists`
     AND requires the id present in `res.setlists` (mutation: invert the filter → red).
   - Assert `syncSetlists` returns a `conflicts` key and `_absorbSetlistSync` consumes it.
4. **Add-song wiring (new assertions in `test-setlist-share-client.js`).**
   - Exactly ONE definition of `mountSetlistAddSongPicker` in the tree; ≥ 2 call sites (both edit
     surfaces — derived by grep, not typed); the module imports `combobox-a11y.js` semantics via
     the same shape `request-a-song.js` uses and contains NO reference to `place-search`;
     no numeric `200` cap literal in the picker/push client code (the number may only arrive via
     `maxSongs`).
5. **Byte-identity spot-lock (extend test 2):** a protocol-1 fixture body (no `since`, no
   `deleted`) driven through the PURE helpers yields guard-inert results (`newerThanWatermark`
   false, deletable-ids unchanged from the existing #1649 assertions), and the structural check
   confirms the cap guard sits under `if ($clientProtocol2)`.

Narrowness check (rule #34's second edge): the prose-ban in guard 3 is scoped to the NEW branch
bodies, not whole files — existing legitimate user-facing copy must not trip it.

---

## 7. Commit breakdown (one PR, atomic commits, smallest-safest-first)

**C1 — `fix(setlist): songs cap is a 413 rejection, never a silent slice (#1662)`**
Server: `setlistCollabMaxSongs()`, de-sliced sanitiser, the three guards (+`reason` keys added to
the two existing 413s), the marked legacy slice. Client: `user-auth.js` reason-branched 413 toast;
collab-detail `push()` revert-on-refusal; `pushTokenEdit()` 413 message. Tests: guard 1 + the
client 413 halves of guard 3 (each mutation-proven). Verify: `php -l`, `node --check`, full test
run, break-red-restore log. **Closes #1662** (comment documents the deliberate protocol-1
carve-out and that it dies when the natives adopt protocol 2 — file that native-adoption note on
the issue).

**C2 — `fix(setlist): one clock for the sync/collab UpdatedAt family (#1675 prep)`**
`$now = userSyncNow($db)` in the sync case; `NOW()` in `setlistCollabPerformUpdate()`; rewrite the
`userSyncNow()` caveat doc-block (it reserved this change by name). No new tests beyond existing
suite green (behavioural no-op on UTC-frame installs); the change is what makes C3's compare sound
and closes the #1649 doc-block's "bounded caveat" for good. Referenced by, does not close, #1675.

**C3 — `feat(setlist): per-row conflict-safe sync upsert + client absorb (#1675, #1660)`**
Server: the two pure helpers, the three-way loop branch, `conflicts` response key, audit field,
the `:3535` doc-comment rewrite. Client: `syncSetlists` returns `conflicts`;
`_absorbSetlistSync` server-wins filter (+ present-in-response belt) + toast. Tests: guard 2 +
guard 3's conflict half + guard 5. Server and client halves land in ONE commit deliberately —
shipped apart, a conflict would be locally resurrected by the current-wins union and loop (§3.5),
so the commit is only atomic-revertable as a pair. **Closes #1675 and #1660** (cite C2+C3 SHAs on
both; #1660's close cites the doc-comment now matching the code).

**C4 — `feat(setlist): add-a-song picker on both shared edit surfaces (#1802)`**
Shell block in `setlist-shared.php`; `mountSetlistAddSongPicker()`; wiring into the token surface
and the collab-detail surface; the rule-#40 read-back adoption of `res.data.songs` in both push
successes. Tests: guard 4. **Closes #1802.**

**C5 — `docs(setlist): api-docs + wiki + changelog for the sync conflict/cap contract`**
`api-docs.yaml` (the `conflicts` key, the 413 `reason` vocabulary, the cap, the add flow),
CHANGELOG, the Wiki API page, and the standing-tasks close-out sweep (issues, `.claude/` docs,
handoff). No code.

Ordering rationale: C1 is fully self-contained (its 413 changes nothing for any in-cap payload);
C2 is a two-line prerequisite whose blast radius is a clock source; C3 is the flagship and depends
on C2; C4 depends on C1 (its failure path is the 413) and benefits from C3 being present so an
added song can't be LWW-lost. All five ride one PR to alpha per repo convention.

---

## 8. Owner sub-decisions surfaced (defaults picked; none block)

1. **Cap value — default: keep 200.** One function to change; MEDIUMTEXT imposes no ceiling;
   raising it would also silently move the legacy natives' slice point. Trivially changeable.
2. **Protocol-1 clients keep the silent slice — mandated** by this cluster's byte-compat
   requirement, but worth the owner's eyes: #1662 closes with a documented carve-out ("a native
   pushing a 200+ list still loses the tail silently until the native apps adopt protocol 2").
   The alternative (413 natives too) is one `if` — say the word and C1 flips it.
3. **Conflict UX — default: server-wins + toast, no auto-merge, no keep-mine dialog.** The refused
   local edit is replaced by the newer server copy and the user is told to re-apply. A
   "keep mine / take theirs" affordance is a clean later enhancement on top of the same
   `conflicts[]` signal; building it now would gold-plate an event whose normal frequency is
   near-zero.
4. **The single-row collab/token endpoints stay last-write-wins between concurrent
   collaborators** (two people mashing reorder on the same link interleave per-action). Out of
   this cluster's scope; the escalation is an optional expected-`updatedAt` precondition on
   `setlistCollabPerformUpdate()` (A.2's shape). **File a `for consideration` issue** at
   implementation time rather than widening this pass.

---

## 9. Issue actions on landing

- **#1662**: close at C1 (SHA + carve-out note, per §8.2).
- **#1675**: close at C3 (SHAs C2+C3; name the residual legacy-writer exposure from A.4 and the
  A.2 escalation path).
- **#1660**: close at C3 (doc-comment and code now agree; cite both halves).
- **#1802**: close at C4.
- **New (file at implementation)**: (a) `for consideration` — expected-version precondition on the
  collab/token write core (§8.4); (b) native apps adopt sync protocol 2 (`deleted` + `since`) —
  the item that retires both the legacy slice and the legacy LWW writer, referenced from the
  #1662/#1675 close comments.
