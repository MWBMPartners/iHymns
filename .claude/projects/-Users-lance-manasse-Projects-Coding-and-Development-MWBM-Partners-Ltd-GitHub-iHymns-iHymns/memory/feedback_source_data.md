---
name: Never touch SourceSongData
description: The .SourceSongData/ folder must never be deleted, modified, or touched — it is the source of truth for all song lyrics.
type: feedback
---

Never delete, modify, or touch files in `.SourceSongData/`. 

**Why:** User explicitly stated this folder must not be touched during cleanup or any other process. It is the source of truth for all song data.

**How to apply:** When cleaning up old files, restructuring the project, or running any automated process, always exclude `.SourceSongData/` from any destructive operations. Only read from it (e.g., the song parser).
