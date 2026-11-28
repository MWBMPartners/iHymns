# Apple release log

One line per build uploaded from the owner's own Mac with
`appApple/Scripts/check.sh release alpha|beta|appstore`. This is how we record a
build without git tags (project rule #46: no tags, no GitHub Releases).

`check.sh` adds the line for you after a successful upload. It leaves the file
changed but not committed, so the next step is a small "record" pull request
with a build-only title, for example
`chore(apple): record TestFlight build 1.0.0 (202611241530)`. This folder is
ignored by both Apple workflows, so that pull request uses no Mac time and can
never start an upload.

Format (one line, columns split by ` | `):

`date (UTC) | version | build number | lane | commit built | Xcode version | who`

Never put a key, a password or a token in this file.

## Builds

