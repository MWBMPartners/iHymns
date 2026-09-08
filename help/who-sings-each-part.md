# 👥 Who Sings Each Part

> Voice-part labels, echoes, and songs sung in a round.

---

## 🎯 What this is

Plenty of hymns and worship songs hand different lines to different groups — the women sing two lines, then the men, then everybody together. Some also have an **echo**, where one group sings a short phrase and another answers it straight back. A few are sung as a **round** (also called a canon), where several groups sing the same words but each starts a little later than the one before.

Where someone has recorded that for a song, iHymns shows it as real information about who sings, rather than leaving the words "WOMEN" and "MEN" sitting in the middle of the lyrics where a projector would display them as though they were sung.

---

## 📍 What you'll see on a song page

<!-- Corrected 2026-09-08, before this page was ever published — the two
     bullets below were checked against the code in review and both were
     wrong, so no reader was ever shown them. Recorded here anyway so the
     mistakes are not repeated.

     (1) The first bullet said the label above a run of lines could read
     "Everyone". It never can. The group everybody sings in is labelled
     "All" on screen: see the 'all' kind in
     appWeb/public_html/includes/vocal_parts.php, whose label is "All".
     "Everyone sings." is only that kind's internal description, which is
     never shown to a reader, and a song whose words say "EVERYONE:" is
     turned into a label reading "All" by the same file's marker map.

     (2) The second bullet described the whole-line echo treatment (set in
     from the margin, italics, a line down the side) and then said an echo
     "can be a whole line, or just a few words inside a line" — implying
     that one description covered both. It does not. A whole-line echo gets
     `.lyric-line--bg` plus the run's dashed `.lyric-voice-run--bg` border
     in appWeb/public_html/css/app.css. An echo of a few words inside a
     line is a `<span>` that gets `.lyric-voice-span--bg` instead: a dotted
     underline and a small return arrow, with no indent and no line down
     the side, because it sits in the middle of a sentence. -->

- **A small label above the lines.** "Women", "Men", "All", "Choir", or a named singer, sitting just above the run of lines it applies to. These labels are shown in capitals on the page, so you will see WOMEN, MEN, ALL. If several lines in a row are sung by the same group, the label appears once above the whole run rather than on every line.
- **Echoes set apart.** When a whole line is an echo, it is set slightly in from the margin, in italics, with a dashed line down its side. When only a few words inside a line are echoed, just those words are marked — a small return arrow and a dotted underline — so you can see where the answer starts and stops.
- **Nothing at all, for most songs.** Most entries have no voice parts recorded. That is not an error — there is simply nothing to show.

---

## 📽 Rounds in Presentation mode

When a song has a round, Presentation mode adds one extra slide immediately after the section the round belongs to. That slide shows which group comes in when, so you can step through the staggered order in front of a congregation instead of trying to explain it.

Everything else about Presentation mode is unchanged: press <kbd>P</kbd> (or tap **Present**) to open it, step through with the arrow keys, a Bluetooth foot pedal or a swipe, and press <kbd>B</kbd> to blank the screen between items.

---

## 🔊 Screen readers

Each run of lines is announced together with who sings it — "Women", "Women and Men", or "Women, echoed by Backing". The point is that the grouping is spoken as information *about* the song, not read out as if it were part of the words. Before this, a marker like "WOMEN" was simply read aloud in the middle of the lyrics with nothing to say what it was.

---

## ✍️ Recording who sings what (curators)

If you curate songs, open the song in the Song Editor and use the **Who sings** panel on the **Structure** tab: select a run of lines, pick a part from the list (rather than typing one), mark it as an echo if it answers another group, or set up a round. The part is chosen from a fixed list so that exports to other worship software carry the same meaning; a free-text label is available on top of it when you want to show something more specific, such as a soloist's name.

Songs that still carry these markings as plain text in their words show up in **Voice-part suggestions** in the admin menu, where a curator can accept a suggestion (which assigns the part properly and tidies the leftover marker text) or dismiss it. An accepted suggestion can be undone from the same page.

---

## 📥 Bringing songs in from elsewhere

Some worship-software formats record who sings a line. Those markings now come across on import instead of being quietly dropped, and they go back out again when you export.

---

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.
