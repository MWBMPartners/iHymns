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
   - It revokes only that device's token and that device's push registrations.
   - The device remembers "signed out here" in the same kind of storage as the token. Only a manual
     sign-in clears that mark.
   - A token the server rejects also sets the mark. So a lost device, a device signed out from the web, and
     a device whose 30 days ran out all stay signed out until a person signs in again. (A device must not
     sign itself back in with an enrol key after a rejection: the server cannot tell "ran out" from "signed
     out remotely", so allowing one allows both.)

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
| Token dead, or any enrol key that cannot be used (unknown, expired, revoked, wrong platform, switched-off account, device signed out) | 401 with one single answer |
| Same-origin check failed, token tied to a different install, wrong kind of sign-in | 403 |
| Too many tries | 429 |

Clients tell failures apart by status code, never by matching the server's sentence.

## Questions each project must answer for itself

- Is the enrol key on by default? (iHymns: on for Apple devices, with a switch to turn it off.)
- May a device whose sign-in ran out sign itself back in with the key? (iHymns: no.)
- Does your platform sync its secure store at all? (Apple TV may not, so give TV no key.)
