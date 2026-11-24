# One sign-in per device: a design you can copy to another project

Written 2026-11-23 from the iHymns account rewrite (PR 3, epic #2180). It names no iHymns tables or
files on purpose, so it can be copied into SIGNula.id, ClearList or any other app that has a website plus
native apps. Where the text says "the site", read "your server".

## The goal

Each device has its own sign-in. The person can see every device in a list, give each one a name, and sign
one out without touching the others. At the same time, someone who keeps their passwords in a synced store
(for example iCloud Keychain) still only signs in once.

## The rules

1. **A token for each device.**
   - It is 32 random bytes. The server stores only a hash of it, never the token itself.
   - It stops working after 30 days without use, and the clock restarts each time it is used.
   - It stays on the device **without cloud sync and without backup transfer**.
     - Apple: `kSecAttrSynchronizable = false` and also a `...ThisDeviceOnly` accessibility value. The
       second one matters: without it an encrypted backup or a phone-to-phone transfer copies the token
       onto a second phone, and two devices then share one sign-in.
     - Android: Keystore-backed storage that is excluded from backup.
   - One row per device records: a name, the platform, the app version, when it was last seen, how it was
     signed in, and a random "install id" the app makes once and keeps the same way as the token.

2. **One maker, one remover.**
   - Every sign-in route creates tokens through one function.
   - Every "revoke everything" (sign out everywhere, a password change or reset, switching an account off)
     goes through one function. That function also revokes enrol keys (rule 4) and push registrations.
   - Guards in the tests forbid any other place from deleting a person's tokens in bulk or writing to the
     key table. Without that, the next person to add a "delete all tokens" line forgets the keys, and a key
     sitting in the cloud survives a password reset.

3. **One device, one row.** Creating a token for an install id replaces the same person's other tokens for
   that same install id, and moves that install's push registrations across. There is **no periodic
   rotation**: if the reply carrying a rotated token is lost, the device is left holding a dead one.

4. **An optional enrol key, kept in the synced store.**
   - It lives in its own table with its own name and a prefix that can never pass the normal token check
     (iHymns uses `ihek1_`; the normal token is exactly 64 hex characters).
   - It can only be swapped for a new device token. It cannot open anything else.
   - It has a long lifetime that slides forward on each swap (180 days), and a small cap per person and
     platform (5, oldest dropped).
   - Any "revoke everything" revokes it.
   - The swap action must require the `Authorization` header. A browser cookie must never be able to mint
     an app token.

5. **Signing out.**
   - It ends only that device's token and that device's push registrations.
   - The device remembers "signed out here" in the same kind of storage as the token. Only a manual
     sign-in clears that mark.
   - A token the server rejects also sets the mark. So a lost device, a device signed out from the web, and
     a device whose 30 days ran out all stay signed out until a person signs in again.
   - **The server enforces it too, so a client bug cannot undo it.** A token tied to an install id is not
     deleted when it ends: its expiry is set to the past and its name, version and last-seen are blanked. The
     row stays as a small "this install was signed out" note. The key-swap action looks for that note and, if
     the install has one and no live sign-in since, gives the same single refusal as for any unusable key.
     A manual sign-in on the install replaces the note. (Why not guess from timestamps? Without the note the
     server cannot tell "ran out" from "signed out remotely", so allowing one would allow both. The note costs
     no new column: the rows already carry the install id.)
   - **The housekeeping sweep must know about the note.** Expired rows used to be deleted at once. Rows tied to
     an install id are now kept for as long as the key can live (180 days after they end); rows tied to no
     install are deleted when they expire, as before. Forget this and the note disappears after the first
     sweep and the key works again.

6. **Leaving an old shared token.**
   - Before this design, all devices shared one token in the synced store. Each device now swaps that token
     for its own.
   - The swap never revokes the shared token, and new code never deletes or overwrites it. Make that
     impossible in the type system: wrap it in a type that can only read.
   - It simply expires once no older app is still using it.

7. **A per-site switch.** One setting per channel (production, beta, alpha), off by default, and failing
   closed. It gates the swap, the enrol key and the web "sign out everywhere" button. When it is off, new
   apps keep using the shared token, so turning it off is an instant rollback.

8. **Never.**
   - Show a token or enrol key again after it was created.
   - Accept a stored hash as a sign-in.
   - Set a browser cookie from an app route.
   - Let a token tied to one install id create a token for a different install id.
   - Delete a dead shared token from the cloud store.

## Status codes (the contract; the body also carries a short `reason` word)

| Situation | Status |
|---|---|
| Swap or key action when the switch is off, or the database is not ready | 409 |
| Missing `Authorization` header, or a malformed install id or platform | 400 |
| Token dead, or any enrol key that cannot be used (unknown, expired, revoked, wrong platform, switched-off account, device signed out or run out) | 401 with one single answer |
| Same-origin check failed, token tied to a different install, wrong kind of sign-in | 403 |
| Too many tries | 429 |

Clients tell failures apart by status code, never by matching the server's sentence.

## Questions each project must answer for itself

- Is the enrol key on by default? (iHymns, owner decision 2026-11-23: on by default for Apple devices once
  the site switch is on; the app may turn it off, and so may the person on the web.)
- May a device whose sign-in ran out sign itself back in with the key? (iHymns, owner decision: no. The
  server refuses it, with the "ended note" in rule 5, and the app also stays signed out.)
- Does your platform sync its secure store at all? (Apple TV may not, so give TV no key.)

## Where it lives in iHymns, for the people who copy it

Server: one function makes tokens, one function ends them (both in the token helper file); the key rules sit
in their own file; the five actions (swap, make a key, redeem a key, turn keys off, sign out everywhere) sit
in one more file that returns a status and a body so tests can call it without the web entry point. The
guards are a real-database test of every row of the status table, a tree-scanning test that bans any other
bulk token delete, and a promise-check test that ties the privacy page's sentences to the code that makes
them true. Each was shown red by a deliberate break before it was trusted.

## Mistakes this design already caught once (so you do not repeat them)

- A browser cookie must not be able to mint an app token: the swap actions read the `Authorization` header
  only, and a test asserts they answer 400 before they look at any cookie sign-in.
- "Revoke everything" had four hand-written copies, and none knew about the key. One door, plus a guard.
- A sweep that deleted rows the moment they expired would have thrown away the very note rule 5 depends on.
- A test that listed the files to scan by hand missed the new file that makes tokens. Derive the list from
  the tree.
