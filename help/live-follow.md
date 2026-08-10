# 📡 Live Follow & Live Services

> Two ways to follow the songs in real time on your own device.

---

## 🎯 Which one am I using?

iHymns has **two separate** ways to follow along live. They look similar — both use a short code and a bar at the bottom of the screen — but they're started differently, need different things set up, and the codes are not interchangeable.

| | **Live Follow** | **Service Mode** |
|---|---|---|
| Who can start it | Anyone with a free account, from any song | A church administrator, from the admin console |
| What's needed | Just being signed in — nothing else to set up | Your church's venue must already be set up, and you need to be listed as an administrator of your church |
| The code | Six letters/numbers, stays the same the whole time you're live | Changes about every 30 seconds — always read the **current** one off the screen |
| Who can join | Anyone — no account needed | Anyone — no account needed |
| What follows | Just the song | The song **and** which verse/chorus is showing |
| The banner | **Blue** — "Following … live" | **Green** — "Following the service live" |
| How long it lasts | Up to 4 hours | Until the service finishes (capped at 4 hours) |

**They are different features with different codes — a Go Live code will not appear on a church screen, and a church-screen code doesn't come from the song page.**

---

## 🧑‍🎤 Leading with Live Follow

All you need is a free iHymns account — no venue, no church setup, nothing to configure. It's ideal for a band rehearsal, a home group, or handing your set to the AV desk.

1. Sign in, then open any song.
2. Tap **Go Live** in the song's button row.
3. You'll get a six-character code — share it however's easiest (voice, text, chat), or tap
   **Show code** for a big code + QR a phone can scan.
4. Carry on opening songs as normal — everyone following switches with you automatically. A red
   **LIVE** bar now stays pinned to the bottom of *every* page while you're hosting — not just the
   song page — with **Show code**, **Console** and **End** buttons, so you can browse the rest of
   the app without losing your session.
5. **Console** (optional) opens a panel to pick a song and step between verses/chorus without
   leaving whatever page you're on — handy if you'd rather drive from a setlist or search screen.

When you're done, tap **End**.

**A session auto-closes on its own** after a stretch with no genuine interaction from the leader —
merely having the app open in the background doesn't count; opening songs, driving sections, or
touching the screen does. The default is a church-configurable number of minutes; change your own
preference under [Settings](/settings), or ask your church admin to set (and optionally lock) an
organisation-wide value. Keep your screen awake too — if it sleeps for more than about three
minutes, followers are disconnected until it wakes up again, on top of the idle timer. Signing out,
tapping **End**, or going live again (which makes a brand-new code) all end the previous session
straight away.

---

## 🙋 Joining a session

You can join either kind of session from two places:

- **Join Live** on any song page
- **Join a live service** on the home page — this one button accepts either kind of code

Or scan a QR code shown by the leader/venue screen — you'll land on iHymns with a **one-tap
confirm** prompt showing the code, so nothing joins automatically just from opening a link (a link
preview or an accidental tap can never drop you into someone else's session).

Codes aren't case-sensitive, and spaces or dashes don't matter — type it however's comfortable. A real code never contains the letters **0, 1, O, I, L or U**, so if one of those turns up while you're typing, you've probably misread the screen.

Once you're in, a bar appears at the bottom of the screen:

- **Blue** "Following … live" — you're following a Live Follow session
- **Green** "Following the service live" — you're following your church's Service Mode

Tap **Leave** on that bar at any time to stop following.

---

## ⛪ Following your church's service (Service Mode)

No account needed — just:

1. Tap **Join a live service** on the home page (or **Join Live** on a song page — either works).
2. Enter the code shown on the venue's screen.
3. Your screen follows the service automatically as the leader moves through songs and verses.

Codes rotate roughly every 30 seconds, so if one is refused, just read the **current** code off the screen and try again — the latest one on the screen always works.

**For church leaders:** before your congregation can use Service Mode, your site administrator needs to run a one-off setup step (a couple of buttons under Database Setup — this only needs doing once per site), and your church needs a venue set up in the admin console. Once that's done, an administrator starts and projects the code from Service Projection, or drives it from a phone with Lead a Service. If you start an ad-hoc (unscheduled) service in the evening and it shows as finished right away, pick a real scheduled service time that covers now instead — ad-hoc services are only reliable in the morning for now.

---

## ⚠️ If it doesn't work

| What you see | Why | What to do |
|---|---|---|
| Tapping **Go Live** gives *"Could not start the session: Unknown column 'Channel'…"* (or a similar error) | Your site administrator hasn't run the one-off setup step yet | Ask them to run the two Database Setup cards for live sessions |
| No **Go Live** button at all | You're signed out — it only appears when you're signed in | Sign in, then reload the page |
| *"That doesn't look like a valid session code."* | The code isn't 4–12 letters/digits once spaces and dashes are stripped out | Re-type the code. Remember it never contains 0, 1, O, I, L or U |
| *"That code isn't active. Check the screen and try again."* | The code has moved on, or you're on the wrong site | Read the **current** code straight off the screen |
| *"Session not found or ended."* even though the leader's screen shows LIVE | You're probably on a **different iHymns site address** to the leader | Make sure you're both on exactly the same address (e.g. both on `ihymns.app`, or both on the test site) |
| Same message, but you're genuinely on the same address | The leader's screen fell asleep, the code was mistyped, or the 4-hour limit was reached | Ask the leader to wake their screen (it revives automatically), then re-join |
| *"The live session has ended."* / *"The service has ended."* mid-way through | The leader ended it, signed out, went live again, their screen slept, or time ran out | Get the new code and re-join |
| *"Your live session ended (closed or timed out after inactivity)."* — seen by the **leader** | The session auto-closed because there was no genuine interaction for a while (having the app merely open doesn't count) | Tap **Go Live** again for a fresh code; adjust the idle timeout under Settings if it's closing sooner than you'd like |
| *"iHymns is down for maintenance — try the code again in a minute. The leader's code is fine."* | The site is briefly in maintenance mode | Wait a minute and try again — nothing is wrong with the code |
| *"Too many requests. Please try again later."* | You (or your network) tried too many times in a short spell | Wait a minute, then try again |
| *"Leave the session you're following before hosting your own."* | One device can't follow a session **and** lead its own at the same time | Tap **Leave** (or **End**) first |

---

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.
