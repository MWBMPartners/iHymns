# 📤 Exporting Songs to Your Projection Software

> Download song words straight into the format your projection software reads.

---

## 🎯 What this is

If your church runs ProPresenter, OpenLP, OpenSong, VideoPsalm, FreeShow, or Proclaim — or you just want a plain chord-sheet file — iHymns can export a song (or a whole songbook) into that format so you can import it directly instead of retyping the words.

---

## 📍 Where to find it

Look for the **Export ▾** button in two places:

- **A song page** — exports that one song.
- **A songbook page** — exports every song in the songbook at once.

Tap **Export ▾** and pick a format from the dropdown. Your browser downloads a file, ready to open or import in the matching program.

---

## 📄 Available formats

| Format | What you get |
|---|---|
| **ProPresenter 6** | A file ProPresenter 6 can import as a song/playlist. |
| **ProPresenter 7+** | A file for the newer ProPresenter 7 and later. |
| **OpenLyrics / OpenLP** | The OpenLyrics XML format OpenLP reads natively. |
| **OpenSong** | An OpenSong song file. |
| **VideoPsalm** | A file VideoPsalm can import. |
| **FreeShow** | A file FreeShow can import. |
| **Proclaim** | A file for Faithlife Proclaim. |
| **ChordPro** | A plain-text chord-sheet file (`.cho`), readable by any ChordPro-compatible app or a text editor. |

Exact formatting (verse/chorus labels, chords, metadata) follows each program's own file layout as closely as possible — you may still want to skim through the imported song once before using it live.

---

## 🖨 Printing & saving as PDF

You can also print a song or a set list on paper — or save it as a **PDF** — without any projection software:

- **A song** — use the **Print** option on the song page. Curators can design **print templates** (which blocks appear, page size, chords, and more) on **Manage → Print Templates**; the print dialog then offers those templates.
- **Download PDF** — when you're **signed in**, a **Download PDF** button sits beside Print in the template dialog. It builds the same layout on the server and downloads a PDF named after the song — no browser print dialog needed. Signed out, you'll just see Print (whose browser dialog still offers "Save as PDF").
- **A set list** — open the set list and use **Print**, the browser's **Save as PDF**, or — signed in — **Download PDF**, which renders the *whole* list as one file: a cover page, the running order, then each song's words in order.

### CCLI copy reporting

If you're **signed in**, your organisation holds a **CCLI licence**, and the song carries a CCLI number, printing or downloading asks *how many copies* you're making. iHymns logs them for your organisation's CCLI report (**Manage → CCLI Usage Report**) and adds the required CCL notice to the printed footer automatically. For everyone else nothing changes — there's no prompt and no footer notice.

### Songbook & number on printouts

The **Songbook + number** block on a print template is **context-aware**: it prints the songbook and the song's number **only for songs in a published (official) songbook**. A song that lives in an **unofficial songbook** or a **catalogue** — which don't have song numbers — prints **nothing** for that block, because a book reference and number aren't relevant to that song. You don't need to configure anything; official-hymnal songs show their book and number as normal, and everything else quietly omits it.

---

## 🛠 If nothing downloads

If the **Export ▾** menu opens but tapping a format doesn't produce a download:

1. **Refresh the page once.** You may be running an older cached version of iHymns — a refresh picks up the latest version.
2. Still nothing? Try a different format to rule out a one-off issue with that exporter, then [open an issue](https://github.com/MWBMPartners/iHymns/issues) with the song, songbook, format, and device/browser you were using.

---

Copyright &copy; 2026 MWBM Partners Ltd. All rights reserved.
