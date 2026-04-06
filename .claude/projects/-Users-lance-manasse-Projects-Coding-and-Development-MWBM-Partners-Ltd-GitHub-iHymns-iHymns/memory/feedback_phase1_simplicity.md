---
name: Phase 1 is a first iteration — keep it simple
description: Phase 1 (v1.x.x) with local JSON song data and file-based architecture has inherent limitations. Don't over-engineer — Phase 2 with iLyrics dB API will solve the data distribution problem properly.
type: feedback
---

Phase 1 (v1.x.x) with local songs.json is a first iteration only. The file-based data distribution (copying songs.json between directories, path resolution gymnastics) is a known limitation.

**Why:** The user explicitly called out the complexity of managing songs.json paths across platforms/environments as a reason this needs to stay a first iteration. Phase 2 (v2.x.x) with the iLyrics dB API will eliminate this entirely — songs come from an API endpoint, no file copying needed.

**How to apply:** Don't over-engineer Phase 1 data distribution. Accept the current "copy songs.json into each deploy directory" approach as good enough. Focus effort on features, not on perfecting the file-based architecture. When Phase 2 comes, all the path/copy logic gets replaced with API calls.
