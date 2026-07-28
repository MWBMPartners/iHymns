#!/usr/bin/env python3
"""
ha_audit_paths.py — shared path resolver for the HA cross-source integrity
audit (`.github/workflows/maintenance-ha-integrity-audit.yml`).

PURPOSE (the bug this exists to prevent):
The 2026-07-14 run of that workflow failed with:

    Expected per-hymn report at .SourceSongData/Himnario Adventista [HA]/
    _integrity-check.md but it doesn't exist

`ha-audit-aggregate.py` had that path HARDCODED as a guess. The real path
is computed by `SDAHymnals_SDAHymnal.org.py`'s own `book_dir_for_site()`
(title + abbreviation + BCP-47 language suffix, #780) — and that
composition changed shape after the aggregator's hardcoded guess was
written, so the two silently drifted apart. Meanwhile the "Pre-fetch the
ChristInSong HA extract" step passed `--site ha` to ChristInSong.app.py,
which has no such flag (only `--hymnal`, keyed `es` for the Spanish HA
songbook, NOT `ha`) — so that step always failed and was silently
swallowed by its own `|| echo "::warning::..."`, meaning the two
scrapers' comparison files could never even land in the same folder for
`SDAHymnals_SDAHymnal.org.py --prefer-source cis` to diff against.

ELI5: two different scripts both write files for "the Spanish HA
hymnal", but each one picks its OWN folder name for it, and a third
script (the CI report-writer) was just guessing a THIRD name. This file
is the one place that asks each scraper directly "what folder do YOU
call this hymnal", so nothing has to guess (or drift out of sync) again.

DETAILED — both `sdahymnal_ha_book_dir()` and `christinsong_es_book_dir()`
load the actual scraper module (via importlib, since neither filename is
a valid Python identifier for a plain `import` statement) and call into
its real path-composition logic — never a re-typed copy of the string
template. Importing either module is side-effect-free: both scrapers only
touch the network / argparse inside their own `if __name__ == "__main__":`
block, so loading them here as a library does not scrape anything.

Used by:
  - the workflow's "Pre-fetch the ChristInSong HA extract" step, to
    relocate ChristInSong's output into the exact folder
    SDAHymnals_SDAHymnal.org.py's `--prefer-source cis` scan will look
    in (the two scrapers otherwise never agree on a folder name for the
    same songbook — see book_dir_for_site()'s doc-block).
  - `ha-audit-aggregate.py`, to resolve the per-hymn report path instead
    of hardcoding it.

Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
"""

import importlib.util
import os
import sys
import types

_REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(
    os.path.dirname(os.path.abspath(__file__))
)))  # .../<repo>/.github/workflows/scripts/ha_audit_paths.py -> <repo>
_SCRAPERS_DIR = os.path.join(_REPO_ROOT, ".importers", "scrapers")

SDAHYMNALS_SCRIPT   = os.path.join(_SCRAPERS_DIR, "SDAHymnals_SDAHymnal.org.py")
CHRISTINSONG_SCRIPT = os.path.join(_SCRAPERS_DIR, "ChristInSong.app.py")


def _load_module(path: str, name: str) -> types.ModuleType:
    """
    Load a .py file as an importable module by explicit path, bypassing
    the normal `import` statement (which can't handle a filename with
    dots in it, like "ChristInSong.app.py"). Side-effect-free for both
    scrapers this module cares about — see this file's module doc-block.
    """
    if not os.path.exists(path):
        raise FileNotFoundError(f"Expected scraper script not found: {path}")
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise ImportError(f"Could not load module spec for {path}")
    module = importlib.util.module_from_spec(spec)
    # argparse-driven scripts read sys.argv even at import time in some
    # layouts; neither of these two do (their arg parsing lives inside
    # `if __name__ == "__main__":`), but scrub argv defensively anyway so
    # a future refactor of either scraper can't turn this into a crash
    # or — worse — an accidental network call from this "just resolve a
    # path" helper.
    saved_argv = sys.argv
    sys.argv = [path]
    try:
        spec.loader.exec_module(module)
    finally:
        sys.argv = saved_argv
    return module


def sdahymnal_ha_book_dir(output_dir: str) -> str:
    """
    The real on-disk folder `SDAHymnals_SDAHymnal.org.py --site ha` reads
    from / writes to under `output_dir` — i.e. where it writes
    `_integrity-check.md` when run with `--prefer-source`.
    """
    mod = _load_module(SDAHYMNALS_SCRIPT, "sdahymnals_scraper")
    return mod.book_dir_for_site("ha", output_dir)


def christinsong_es_book_dir(output_dir: str) -> str:
    """
    The real on-disk folder `ChristInSong.app.py --hymnal es` writes to
    under `output_dir` (the Spanish "Himnario Adventista" / HA hymnal —
    note the HYMNALS key is `es`, the BCP-47 language subtag, NOT `ha`).
    """
    mod = _load_module(CHRISTINSONG_SCRIPT, "christinsong_scraper")
    info = mod.HYMNALS["es"]
    return os.path.join(
        output_dir,
        mod.compose_subdir(info["title"], info["abbrev"], info.get("lang", "")),
    )


def _main() -> int:
    """
    CLI entry point for the workflow's shell steps: prints one resolved
    path per invocation so `run:` blocks can capture it into a shell
    variable without duplicating any Python here.

        python3 ha_audit_paths.py sdahymnal-ha .SourceSongData
        python3 ha_audit_paths.py christinsong-es .SourceSongData
    """
    if len(sys.argv) != 3:
        print(
            "usage: ha_audit_paths.py <sdahymnal-ha|christinsong-es> <output_dir>",
            file=sys.stderr,
        )
        return 2
    which, output_dir = sys.argv[1], sys.argv[2]
    if which == "sdahymnal-ha":
        print(sdahymnal_ha_book_dir(output_dir))
    elif which == "christinsong-es":
        print(christinsong_es_book_dir(output_dir))
    else:
        print(f"unknown selector: {which}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(_main())
