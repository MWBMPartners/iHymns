// SongComponent.swift
// IHModels
//
// ELI5: One verse, chorus, or bridge of a song — a chunk of lyric lines that
// belong together and get shown/sung as one unit.
//
// DETAILED: Mirrors the shape `includes/lyric_lines_read.php`'s
// `lyricLinesAssembleComponents()` emits — the web project's ONE authoritative
// lyric-line read path (`.claude/CLAUDE.md` rule #25: `tblLyricLines` is the
// source of truth; a raw `LinesJson` reference anywhere outside that
// assembler is a banned regression). This DTO is deliberately shaped to
// match that assembler's real output field-for-field (verified against a
// live `?action=song_detail` pull, `Tests/Fixtures/song_detail.json`)
// rather than the shorter `#/components/schemas/SongComponent` in
// `api-docs.yaml`, which only documents `type`/`number`/`lines` — the real
// payload also always carries `lineIds`, and sometimes `chords` /
// `language` / `lineLanguages`.
import Foundation

/// One song component (verse/chorus/bridge/etc.) with its lyric lines.
///
/// ELI5: "Verse 1: these four lines."
public struct SongComponent: Sendable, Hashable, Codable {

    /// Component kind, e.g. `"verse"`, `"chorus"`, `"bridge"`.
    ///
    /// DETAILED: Plain `String`, not an `enum` — same open-vocabulary
    /// reasoning as `ExternalLink.category`. The web renderer already treats
    /// `chorus`/`refrain` specially (italic, `.claude/CLAUDE.md`'s #1337
    /// note) via a string compare, not a fixed case set, so native code
    /// should match that rather than risk a decode failure the day a new
    /// component type (e.g. a bridge variant) appears server-side.
    public let type: String

    /// This component's number within its kind, e.g. verse 1 vs verse 2.
    public let number: Int

    /// The lyric lines, in display order.
    public let lines: [String]

    /// Per-line chord strings, parallel to `lines` (same length/order) —
    /// `nil` when NO line in this component carries a chord (the common
    /// case today; confirmed by `lyricLinesAssembleFromRows()`'s
    /// `$anyChords` check, which emits `null` rather than an all-`nil`
    /// array). An element WITHIN the array can still be `nil` for a line
    /// that itself has no chord even when a sibling line does.
    public let chords: [String?]?

    /// The component's own language tag, or `nil` if unset. A per-line
    /// override lives in `lineLanguages`, sparse, only when it differs.
    public let language: String?

    /// The stable `tblLyricLines.Id` for each line, parallel to `lines`.
    /// Per-line enrichment (translations/annotations, CLAUDE.md rule #21)
    /// anchors on these — never on a positional index into `lines`.
    public let lineIds: [Int]

    /// Sparse per-line language override, parallel to `lines` — present
    /// ONLY when at least one line's language differs from `language`
    /// (mirrors the server's sparse-emission rule exactly, so `nil` here
    /// means "every line uses the component default," not "unknown").
    public let lineLanguages: [String?]?
}
