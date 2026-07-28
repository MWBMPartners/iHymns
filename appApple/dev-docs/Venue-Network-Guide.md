# iHymns TV Remote — Venue Network Guide

A plain-English guide for whoever sets up (or troubleshoots) the Wi-Fi/LAN
that the iHymns TV Remote runs on — a venue operator, an AV volunteer, an
IT-minded church member. You do **not** need to know anything about
programming to use this guide.

This is a **developer/admin document** — it is never bundled into any
iHymns app (see `appApple/dev-docs/README.md`'s "why it can't ship"
guarantees).

## 1. What the iHymns TV remote needs from your network

| Requirement | Why |
|---|---|
| The TV and the remote (phone/iPad/Mac) are on the **same Wi-Fi/LAN**, or reachable through a **routed VPN** | The remote talks to the TV directly over the local network — there is no cloud server in between for the remote-control connection itself. |
| Devices on that Wi-Fi are allowed to talk to **each other** | Some networks (especially guest Wi-Fi) deliberately block this — see §2 below. |
| **TCP port 7269** open between the remote and the TV | This is the one port the whole feature uses. |
| Bonjour/mDNS (optional) | Lets the remote find the TV automatically, so nobody has to type an address. Not required — "Connect by Address" (§3) works without it. |

If all four are true, pairing a remote with the TV is a one-time, few-second
process: scan a QR code (or enter a short code) shown on the TV, and you're
done.

## 2. AP / client isolation — the single most common blocker

**What it is:** many Wi-Fi networks — especially **guest networks** — are
configured so that devices connected to the same Wi-Fi **cannot see or talk
to each other**, even though they can all reach the internet. This is a
security feature (it stops one guest's laptop from snooping on another's),
but it also stops a phone from ever reaching a TV on the same Wi-Fi.

**Why it matters here:** the iHymns TV Remote is a phone-to-TV connection
entirely on your local network — if the Wi-Fi has this isolation turned on,
the phone will never be able to find OR reach the TV, no matter what you do
in the app.

**What to look for in your router/access-point settings** — vendors call
this feature different things, so look for any of:

- "AP isolation"
- "Client isolation"
- "Station isolation"
- "Guest mode" (often ties client isolation on by default)
- "Wireless isolation"
- "Inter-client privacy"

**The fix:** put the TV and the remotes on a **non-isolated SSID or VLAN** —
either a dedicated "AV" network with isolation turned OFF, or your main
(non-guest) Wi-Fi. Do not simply disable isolation on your public guest
network for every guest — scope it to the network the AV equipment actually
uses.

## 3. Connecting over a VPN

If your congregation's devices connect in over a VPN (a remote campus, a
home visitor connecting into the church's network), expect **connectivity
without discovery**: the VPN can usually route the actual connection
traffic, but Bonjour/mDNS (the "automatic discovery" mechanism) is
multicast-based and typically does **not** cross a routed VPN tunnel.

**What this looks like in the app:** the "TV Remote" screen shows no nearby
TVs at all, even though the TV is genuinely reachable.

**The fix:** use **Connect by Address** — type in the TV's IP address (and,
if it's not the default, its port) instead of waiting for it to appear in
the list. The TV shows its own address and port on its **Settings → Remote
Control** screen. The app remembers the last address that worked for each
TV, so this is a one-time inconvenience per TV, not something to repeat
every time.

## 4. The iPhone's Local Network permission

The first time iHymns looks for (or connects to) a TV, iOS/iPadOS shows a
system prompt asking whether iHymns can find devices on the local network.
This is a standard Apple privacy permission — iHymns needs it to work at
all (both for automatic discovery AND for typing in an address directly).

- If a user taps "Don't Allow" by mistake, the remote screen will never find
  anything, and Connect by Address will silently fail to reach anything
  either.
- **To re-enable it:** Settings → Privacy & Security → Local Network → turn
  on iHymns.

## 5. Pairing, briefly, for operators

There are two ways a remote pairs with a TV:

1. **Scan the QR code** shown on the TV (Settings → Remote Control, or the
   pairing overlay when you tap "Pair a remote" on the TV). This is the
   **preferred** method — it verifies the TV automatically, with nothing for
   the person pairing to double-check.
2. **Connect by Address** (§3) — used when the TV can't be found any other
   way. Because there's no QR code involved, the app shows a long
   "fingerprint" (a unique code identifying that specific TV) and asks the
   person to compare it against the same fingerprint shown on the TV's own
   Settings screen. **Teach your operators to actually do this comparison**
   — it's the one thing standing between "definitely my TV" and "possibly
   something else on the network." If the two don't match, the person should
   cancel and NOT continue.

Either way, the actual 6-digit pairing code:

- Is only valid for a short time (2 minutes) before it's replaced.
- Can only be used **once** — a second attempt with the same code fails.
- Shows up on the TV's screen (and in its trusted-remotes list) the moment
  someone successfully pairs, so it's obvious to the operator who just
  connected.
- Can be **revoked** with one tap from the TV's Settings → Remote Control
  screen if a remote shouldn't be trusted anymore.

## 6. Firewall / managed-network checklist

For an IT-managed network, allow:

- **TCP 7269** between client devices and the Apple TV (bidirectional, same
  subnet or routed).
- **UDP 5353** (mDNS) if you want automatic discovery — the service name is
  `_ihymns-remote._tcp`. Not required if everyone will use Connect by
  Address instead.
- Nothing else — the remote-control feature itself has **no internet
  dependency**. (The TV app's other features — song content, sign-in, etc.
  — do need normal internet access, but that's unrelated to the remote.)

## 7. Troubleshooting flow

The iHymns app has a built-in **"Troubleshoot Connection"** screen (from the
TV Remote tab: "Can't find your TV?") that runs through the same checks this
section describes, in the same order, so the app and this guide never tell
a different story:

1. **Is Local Network access allowed?** If not, the app tells you exactly
   where to turn it on (§4).
2. **Does discovery find anything at all?** If not, and you also can't
   directly reach the TV's address, this usually means: wrong Wi-Fi, client
   isolation, a firewall, or the TV isn't running iHymns right now.
3. **Does discovery find something, but nothing verified reachable?** This
   is the client-isolation signature (§2) — devices can "see" each other's
   names via mDNS but can't actually open a connection.
4. **Does a direct address test work, but discovery doesn't?** This is the
   VPN/multicast-blocked signature (§3) — use Connect by Address, which
   already works.
5. **Everything checks out?** Great — go back and pair by QR code (or
   Connect by Address if you already know the TV's address).

If none of this resolves it, use the **server-driven fallback in §8** instead
of a direct phone-to-TV connection.

## 8. When the LAN remote can't work: follow the service on the TV instead (#1428)

The LAN remote in §1–§7 is a **direct, same-network** phone→TV connection —
it's fast (sub-100 ms) and needs no internet, but it depends on the venue
network letting the two devices reach each other (§2 AP isolation, §3 VPN,
§6 firewalls can all block it). It's also same-room only.

When that direct path can't be made to work — or you're driving an
**overflow / multi-site** screen that isn't on the same LAN at all — the TV
can instead **follow the live Service session over the internet**, the same
way a congregant's phone follows along:

1. On the projector Apple TV, open the **Live** tab and enter the venue's
   **join code** (the rotating code shown by *Service Projection* on the web,
   `/manage/service-projection`).
2. The TV joins as the venue **projector** and mirrors whatever the service
   leader is currently showing — song, section, and blackout/logo — polling
   the server about once a second.
3. Drive the songs from the web console (*Service Projection* or *Service
   Lead*) or, once §PR-14's native mirror is in use, from the phone/iPad LAN
   remote — either way the followed TV stays in sync.

Trade-offs vs. the LAN remote: this path **needs internet** on the TV and has
**seconds** of latency (not sub-100 ms), and it never carries lyric text over
the wire — the TV fetches each song under its own account, so content gating
and any licensed-content unlock apply on the TV exactly as they would in a
browser. If the internet drops mid-service the projection holds its last
state and resumes when connectivity returns.
