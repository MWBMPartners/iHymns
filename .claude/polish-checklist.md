# Polish checklist — "does any of this look unfinished?"

Owner rule, 2026-10-05 (issue #2142). Run it before calling user-facing web work done, and
as a full pass at least once a month. `tests/test-polish-guard.js` checks the mechanical items on
every pull request; everything else here needs eyes, ideally a real browser.

Done means: every item below was checked, each finding was fixed or filed as an issue, and the
report says which items were **not** checked and why. "Looks fine" without having looked is not a
pass.

## Addresses and previews
- No default or preview hosts (vercel.app, netlify.app, pages.dev, github.io, ngrok, localhost,
  example.com) in anything a visitor or crawler can see: canonical links, og:url, site map,
  manifest, robots, structured data.
- Every page has its own title, description, canonical URL, favicon and share image. Check what a
  link preview sees (no JavaScript), not just the tab after the app has loaded.
- Text in previews is escaped once: an apostrophe shows as an apostrophe.
- The site map lists the same address each page names as canonical.

## The pages people skip
- A real 404 page with a way back, and an honest status code (404 / 410, not 200).
- Loading, empty and error states on every list, search and fetch. Messages say what happened and
  what to do next. No "Something went wrong", no "HTTP 500", no raw error text.
- A no-JavaScript message instead of an endless spinner.

## Structure and accessibility
- One `h1` per page, no skipped heading levels.
- Every image has alt text (`alt=""` when decorative). Icon-only buttons have a name.

## Leftovers
- No console errors or routine `console.log` on a normal page load.
- No developer notes in served HTML (comments, issue numbers in labels such as "Modern (#865)").
- No placeholder text (lorem ipsum, TODO, coming soon) and no blank placeholder images.
- No development files reachable from the web (source files, notes, test data).
- No library loaded on every page that nothing uses. Check what the home page downloads.

## Every screen size
- 320, 375, 768, 1024 and 1440 px wide, plus one landscape phone (about 600×400).
- Very long titles and words, empty data, and error states at each width. No sideways scrolling.
- Tap targets at least 40–44 px.

## Consistency
- One brand colour for primary actions (no framework-default blue next to the brand colour).
- Corner radius, shadows, font sizes, button styles and icon set come from the shared tokens, not
  one-off values.

## Things that look clickable
- Every button and link does something. Inline `onclick=` handlers are refused by the site's
  security policy, so they look fine and do nothing.
- Every link a page emits is a route the site serves (rule #33).
