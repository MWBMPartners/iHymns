# What's New in iHymns

Friendly, plain-language notes about recent updates — what changed for **you**,
with none of the behind-the-scenes technical detail.

> This file is the source for the in-app **What's New** page. Write every entry
> for an ordinary worshipper or worship leader: describe what they can now *do*,
> not how it was built. No file names, code, version internals, database tables,
> or issue numbers — those live in `CHANGELOG.md` for developers. Keep the same
> `## <version> — <date>` heading style and `- ` bullets so the app can display
> it. See `.claude/whats-new-style.md` for the full house style.

## 1.3.0 — 30 August 2026

- **Songs where different groups sing different lines** — Hymns and worship songs
  often mark parts for different groups: the women sing two lines, then the men,
  then everyone together. Until now those markings were stored as if they were
  words of the song, so screen readers read them out as lyrics and projectors
  displayed them as though they were sung. They are now recorded properly as who
  sings what, shown as a clear label above each group's lines, and announced
  correctly to screen readers. Songs with an echo — a short phrase answered back
  by another group — are handled too, as are songs sung in a round, where groups
  sing the same words starting at different times. If you import a song from
  OpenLP or a similar program that already records these markings, they now come
  across instead of being quietly dropped.

- **Shuffle now respects your chosen languages** — If you've picked the
  languages you read in Settings, the Shuffle button now only offers you
  songs in those languages. Before, shuffling from "All songbooks" could
  hand you a song in a language you'd filtered out. Songs that aren't
  marked with a language still come up, just as they do everywhere else in
  the app. If nothing at all matches the languages you've chosen, iHymns
  now tells you that's the reason, rather than just saying nothing is
  available.

- **Clearer colours and better keyboard/screen-reader support** — A pass
  through the whole app to make it easier to use for people with low
  vision or who rely on a keyboard or screen reader. Some admin buttons
  and status badges now use easier-to-read colours; several pop-up panels
  (the keyboard-shortcuts guide, presentation mode, song comparison, and
  the quick song-number picker) now properly keep your keyboard focus
  inside them and hand it back to where you were once you close them;
  browser tabs and page titles for songs, songbooks, themes, people,
  publishers, tunes and works now show the actual name instead of a
  generic label; the "Emphasise Links" accessibility option now adds an
  underline as well as colour, so links stand out clearly for everyone
  who turns it on; and dozens of smaller wording and labelling gaps were
  filled in across the admin area and the classic song editor.
- **Choose which sites appear in search engines** — Admins can now control
  whether each copy of iHymns — the live site, the beta preview, and the
  dev site — shows up in search engines like Google. The live site is
  listed as before; the preview and dev sites are now kept out of search
  results by default, so an in-progress version of a song page can no
  longer turn up in a web search. Each can be switched on or off from the
  admin Settings page, and the site itself keeps working normally either
  way — this only changes whether it's listed.

- **A guided helper for switching content locking on** — Turning on the
  setting that limits copyrighted songs to the right membership level is
  now a short, step-by-step guide instead of a bare switch: it explains
  what will change, shows you the numbers first (how many songs, how much
  audio and sheet music), checks that a licence is actually in place, lets
  you try it out on one real song before committing, and gives you a
  one-click way to undo it afterwards. The plain switch is still there if
  you'd rather flip it yourself.
- **A guided setup helper for connected services** — Admins can now turn on
  three optional extras — QR codes, bot protection on sign-up/sign-in forms,
  and the connected-apps switchboard — with a short, step-by-step guide
  instead of a bare settings form: what you'll need, where to paste it, and
  a built-in "test the connection" check before you're done, so you know
  straight away whether it's actually working. The full settings screen is
  still there if you'd rather set it up yourself.
- **The guided set-up helper now also covers email, Apple sign-in and
  webhooks** — the same step-by-step "what you'll need, where to paste it,
  test the connection" guide above now also walks admins through setting up
  the app's email service, Sign in with Apple, and outbound webhooks for
  partner systems. Each one finishes with a real check — a genuine test
  email, a check that the saved Apple sign-in key is valid, or a look at
  whether webhook delivery is switched on — so you know straight away
  whether it's actually working, without needing to hunt through a long
  settings form.
- **A guided walkthrough for getting a brand-new install running** — Setting
  up iHymns for the first time is now a short, friendly, step-by-step guide
  instead of one long checklist: connect the database, bring it up to date,
  see what starter content is already included, optionally connect a few
  extra services, and finish with a plain "yes, everything's ready" check —
  each step explained in everyday language along the way. The detailed
  setup page is still there if you'd rather do it yourself.
- **Clearer, higher-contrast text and buttons in the admin area** — Several
  warning and info messages, outlined buttons, and a few links in the admin
  screens were hard to read against a light background — pale yellow or
  pale blue text, and links that all but disappeared. They're now easier to
  read in Light mode, without changing how anything looks in Dark mode.
- **The guided setup screens are clearer for keyboard and screen-reader
  users, and safer** — In the step-by-step guides for turning on content
  locking and setting up the database, a few buttons now clearly announce
  what just happened instead of staying silent, a broken "undo" button in
  one of the guides now actually works, and the database setup page's
  buttons are better protected against being triggered from outside the
  app.
- **More of the app works properly with a keyboard and screen reader** —
  the tag pills in the favourites tag editor can now be reached and turned
  on/off with the keyboard (they used to be mouse-only); the "add custom
  tag" box, the "add tag" button, and the compare-with search box now
  announce what they're for; the link editor used on songs, songbooks,
  credit people and works pages now properly names its type, URL, note and
  "Verified" fields; and a couple of small icon-labelling glitches were
  fixed on the musicians page and the favourites list.
- **See your own notes and highlights on a song** — When you're signed in,
  you can now mark up any song's words just for yourself: colour-highlight
  a line, or attach a short note to a line or to the whole song. It's a
  private layer only you can see — nobody else looking at the same song
  sees your marks or notes.
- **Turn pages hands-free while you play** — If you use a Bluetooth foot
  pedal, or a MIDI foot controller plugged into your device, you can now
  step forward and back through a song's verses and choruses without
  touching the screen — handy when your hands are busy with an instrument.
  Works on a song page and in Presentation mode.
- **More ways to display chords** — Chords can now be shown as Nashville
  numbers or as Do-Re-Mi (solfège) instead of plain letters, a capo
  setting shows you the exact shape to play while the chord name stays
  correct for however the song actually sounds, an octave marker for
  singers reading an octave up or down, and — on a wider screen — an
  optional two-column layout that fits more of a song's chords in view at
  once. All are personal preferences that stick between visits.
- **Imports now keep a hymn's exact singing order** — Some worship-software
  files record a very specific order to sing a song in — perhaps a chorus
  that repeats between verses, or a verse that's meant to be skipped.
  Importing a song like that now keeps its intended order instead of just
  listing its sections in file order.

## 1.1.0 — 28 August 2026

- **A guided helper for starting a new song** — Adding a new song to the
  catalogue can now be a short, friendly walkthrough: pick the songbook,
  check the number you want is actually free, add a title (and any other
  names the song is known by), and start it off with a verse/chorus shape —
  then it opens straight in the editor ready for the words. The plain "New"
  button is still there if you'd rather jump straight in.
- **A guided setup helper for new organisations** — Setting up a brand-new
  organisation is now a short, friendly, step-by-step walkthrough: give it a
  name, add its licence details, and invite its first members, all in one
  guided flow — with a summary at the end showing exactly what was created.
  The familiar form is still there if you'd rather fill everything in
  yourself.
- **A guided setup helper for live services** — Organisation admins can now
  set up a venue and its regular service time in a few friendly steps, with
  plain-language help explaining the two ways to go live: a quick, no-setup
  session you start from any song, or a full service with a join code your
  whole congregation can follow. The step-by-step forms you already know are
  still there if you'd rather fill them in yourself.
- **A friendlier start on empty lists** — Landing on an empty list of link
  types, songbooks, venues or organisations now shows a "Get started" card
  pointing you straight at the matching guided helper, instead of just a bare
  "nothing here yet" message.
- **Bring songs in from ProPresenter** — You can now import songs, bundles and
  service playlists straight from ProPresenter 7, keeping their verses,
  choruses, arrangements and copyright details. A ProPresenter service playlist
  arrives as a ready-made set list.
- **Chords travel with ProPresenter files** — Importing a song from ProPresenter
  now brings its chords across, and exporting a song that has chords sends them
  along too — ready to show on a stage display, while the words stay clean for
  the congregation.
- **Your background video travels with a ProPresenter export** — When you send a
  song that has a background video or image to ProPresenter, that media now goes
  inside the file, so it's ready to play on the other machine instead of turning
  up missing.
- **Name your signed-in devices** — The Signed-in devices list now gives each
  device a friendly name on its own — like "Chrome on Windows" instead of
  "Unnamed device" — and you can rename any of them, so it's easy to spot and
  sign out one you don't recognise.
- **Record more than one licence for your church** — An organisation can now hold
  several licences side by side — for example one for the words and a separate
  one for the music — each with its own number and expiry date, all managed in
  one place.
- **Easier to use with a screen reader or keyboard** — A round of accessibility
  work makes the app and the admin area clearer for people who use a screen
  reader or navigate by keyboard: buttons and form fields now announce what they
  do, collapsible sections open from the keyboard, a "skip to main content" link
  jumps past the menus, admin tables read out their column headings, decorative
  icons no longer clutter the reading order, and text and badges have stronger
  colour contrast so they're easier to read.
- **Make links stand out** — A new accessibility setting under Settings →
  Appearance lets you turn on stronger, higher-contrast colouring for the links
  in song details and descriptions, so they're easier to pick out from the words
  around them. It's off by default and remembered on your device.
- **Add a new link provider yourself** — Curators managing Link Types can now
  add a brand-new provider (not just edit the existing ones) with a guided,
  step-by-step helper that suggests a matching web-address pattern and tests it
  live against real examples before you save — or fill in the same details
  yourself with a plain form if you'd rather skip the guided steps.
- **A guided helper for adding a songbook** — Curators can now add a new
  songbook with a step-by-step helper that checks the short code as you type
  and warns you if it's already taken, includes a digit, or would clash with
  an internal code — so it's right the first time. It also explains, in plain
  language, that the code becomes part of every song's web address in that
  book and is best treated as permanent, while the separate display label
  shown to everyone can be changed any time. The original one-page form is
  still there too, for anyone who'd rather fill everything in at once.

## 1.0.0 — 24 August 2026

- **Search suggestions as you type** — Start typing in the search box and a short
  list of matching song titles now drops down; tap one to jump straight to that
  song. The full results list is still right below it, and you can press the down
  arrow to step through the results with the keyboard.
- **Full songbook names everywhere** — The home page's popular and recently-viewed
  songs, and a musician's list of songs, now show each songbook's full name (like
  "Seventh-day Adventist Hymnal") instead of just a short code — matching the rest
  of the app.
- **Browse every theme** — A new Themes page lists every theme from A to Z with a
  quick filter box and letter jumps, so you can find a thematic collection without
  scrolling a wall of tags. Each theme's song count now always matches what you see
  when you open it.
- **Add songs to a shared set list** — When someone shares a set list you're allowed
  to help edit, you can now add songs to it directly, and everyone's changes stay in
  step more reliably even when a few people edit at once.
- **Your church's logo in more places** — Beyond printed song sheets, a church's logo
  can now appear in the app header for signed-in members, as a subtle mark in the
  corner of the live projection screen, and — with a chosen brand colour — on the
  preview picture of a shared set list.
- **Big exports check with you first** — Exporting a very large songbook now asks
  before it starts and shows progress while it builds, so a huge download never looks
  frozen.
- **Imports keep their details** — Bringing songs in from other worship software now
  keeps the copyright line, song number and public-domain markings the file came
  with, instead of leaving them blank.
- **Faster and safer behind the scenes** — The catalogue refreshes more efficiently
  on repeat visits, and signing in has extra protection against repeated wrong-guess
  attempts.

## 0.5300.0 — 18 August 2026

- **See your own church's CCLI report** — Organisation admins can now pull their
  own church's printed-copy usage report straight from the admin area, without
  needing full system-wide report access.
- **Less form-filling in the Song Editor** — Copyright lines, public-domain
  status, and audio/sheet-music indicators now fill themselves in from what the
  catalogue already knows, instead of asking a curator to type or tick them by
  hand.
- **Fewer duplicate entries** — When adding a song, songbook, publisher, tune,
  or person, you now pick from a live search list instead of typing a name —
  so two curators typing the same thing slightly differently no longer creates
  two separate entries for the same thing.
- **Search that shrugs off accents and apostrophes** — Searching for songs,
  songwriters, tunes or places now looks past accents and curly quotes, so
  "Cafe" finds "Café" and "dont" finds "don't". It works the same whether
  you're online or using songs you've saved for offline.
- **Shared set lists stop sharing when they expire** — If you put an end date
  on a set list you've shared, the link now stops showing it once that date
  passes, however it's opened — so an out-of-date set list won't keep going
  round.
- **Reliability and security touch-ups** — Signed-in members no longer see a
  stray "sign in to sync" prompt in Settings, and a round of behind-the-scenes
  security work keeps the app safe without changing anything you'll notice.
- **Name your own song sections** — A curator can give any part of a song its
  own name — "Kyrie", or its language ("isiZulu") in a multilingual song — in
  place of the usual "Verse 1 / Chorus". It's just a label: the app still knows
  it's a verse or a chorus underneath.
- **Medleys** — A song that stitches several hymns together can now be recorded
  as a medley — an ordered list of the songs it's made from — and its page shows
  a "Medley of: …" line so everyone can see what it's built from.
- **Alternative titles now survive export and re-import** — If you export a
  song and bring it back in later, any alternative titles it had travel with
  it instead of being quietly dropped.
- **Keyboard-friendly search results** — On the search page, press the down
  arrow from the search box to move into the results list, keep arrowing to
  step between them, and press Enter to open the one you've landed on.
- **See what changed before you restore an old version** — The Song Editor's
  history list now shows exactly what each past edit changed, so you can tell
  what you're restoring before you commit to it.
- **Handle several songs at once** — In the Song Editor you can now select a
  batch of songs and move, delete, or export them together in one go.
- **Preview a bulk import before it saves anything** — When importing a ZIP
  of songs, tick "preview only" first to see what would be added or skipped
  without actually saving it — useful for checking an unfamiliar bundle before
  committing to it.
- **More than one copyright holder** — A song can now list several copyright
  holders in order, for the cases where credit is genuinely shared.

## 0.5160.0 — 12 August 2026

- **A more inspiring Song of the Day** — The little lyric taster on the home
  page now shows a fuller opening phrase instead of just the first line (which
  is often the same as the song's title), so the daily quote reads as a complete
  thought. On smaller screens it trims neatly with a "…" rather than wrapping.

## 0.5150.0 — 12 August 2026

- **Your church's logo on printed song sheets** — Organisations can now upload
  their logo in several shapes (a main version, wide and stacked layouts, a
  symbol on its own, and more) and add it to a print template, so your printed
  song sheets and PDFs can carry your own branding.
- **Missing Numbers now highlights hidden songs** — When you check a songbook
  for gaps, any number whose only song has been deleted is now flagged, with a
  quick link to review it — so a number isn't quietly held by a hidden song.
- **Clearer, safer help** — The in-app Help &amp; Guides have been rewritten in
  plain, everyday language, with less behind-the-scenes technical detail.

## 0.5100.0 — 12 August 2026

- **A clearer admin area** — If you help look after songs, songbooks or live
  services, the Manage menu and its pages now use plain, everyday language
  instead of technical terms, and related tools are grouped together so they're
  easier to find.
- **Plain-language What's New** — This very page now describes updates in simple
  terms, focused on what you can do rather than how it works behind the scenes.
- **Reliability fixes** — A round of behind-the-scenes fixes so updates install
  cleanly and the site keeps running smoothly.

## 0.5050.0 — 11 August 2026

- **Print and PDF** — Print a single song or a whole set list with a clean,
  easy-to-read layout, or save it as a PDF to email, keep or hand out.
- **Share a set list with a link** — Send your team one link to a set list.
  They can open it, tap straight to any song, and — if you choose to allow it —
  help edit the list too. No account or sign-up needed to follow along.
- **Sort your lists your way** — A new **Sort** button lets you reorder
  songbooks, favourites, search results and more — by name, number, date added
  and other options. Your choice is remembered on your device and your account.
- **Clearer songbooks** — Official and community songbooks now appear together
  with clear labels, and each book shows richer publisher and edition details.
  You can also explore the publishers behind them.
- **Better live services** — When a service is running live, your congregation
  can follow along on their own phones and stay in step with the current song.
- **Smoother export to worship software** — Sending songs to ProPresenter and
  other presentation apps is more reliable.

## Earlier updates — 2026

- **Set lists** — Build a running order, drag songs to reorder it, present it on
  screen, and share it with your team.
- **Works offline** — Save songs and whole songbooks to your device so they're
  there even with no signal.
- **Fast search and favourites** — Search the full text of every song, and tap
  the heart to keep favourites that sync to your account.
- **Comfortable to read anywhere** — Dark mode, a high-contrast option, and
  colour-blind-friendly modes.
- **Sign in your way** — Including Sign in with Apple.
