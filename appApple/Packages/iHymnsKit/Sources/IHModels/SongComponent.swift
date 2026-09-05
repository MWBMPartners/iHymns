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
//
// #2073 UPDATE (commit 16, `.claude/vocal-parts-2073-plan.md` design pass 7
// §"Native" / pass 3 §4.1 + §6.5) — `voices`/`voiceSpans` below are two BRAND
// NEW, SPARSE wire keys the web side is only just starting to emit (curators
// can now say "the men sing lines 3-6" / "just the last three words are an
// echo"). This commit is DECODE-ONLY: it teaches this struct to accept the
// two keys without requiring them and WITHOUT rendering them anywhere yet —
// rendering is deferred to a later, separate commit (`SongComponentView.swift`
// stays untouched here). See this file's own doc comments on `voices`/
// `voiceSpans` below for why that's a safe, additive split.
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

    /// Which voice(s) sing each contiguous run of lines — "the men sing
    /// lines 3 to 6", "everyone, then just the soloist for the last line".
    ///
    /// ELI5: Who's singing right now, grouped into blocks.
    ///
    /// DETAILED (#2073): SPARSE — `nil`/absent on every song until a curator
    /// assigns a voice part, and still `nil` on a component nobody has
    /// touched even after that (mirrors `lineLanguages`'s exact
    /// present-only-when-non-empty rule, same server-side fetcher family).
    /// Given a DEFAULT of `nil` here (rather than requiring every call site
    /// to pass it) so this stays a genuinely additive change: every existing
    /// `SongComponent(type:number:lines:...)` construction across the test
    /// suite keeps compiling unchanged, and a server response recorded
    /// BEFORE this key existed still decodes fine — Swift's synthesized
    /// `Decodable` (no custom `init(from:)` is written anywhere in this
    /// file — see `CodingKeys` note above) already uses `decodeIfPresent`
    /// for every `Optional` stored property, so a missing key degrades to
    /// `nil` rather than throwing, and a server response carrying keys this
    /// struct doesn't know about at all (an unrelated future addition) is
    /// silently ignored — `JSONDecoder` has no "reject unrecognised keys"
    /// mode by default, unlike Kotlin's `kotlinx.serialization`, which is
    /// strict unless `ignoreUnknownKeys = true` is set explicitly (see
    /// `SongViewModel.kt`'s Android twin of this decoder for that config).
    /// DEFERRED: rendering this into `SongComponentView` is a separate,
    /// later commit (`.claude/vocal-parts-2073-plan.md` design pass 7's
    /// commit-16 scope note) — this struct can decode the value today
    /// without anything yet reading it.
    public let voices: [VoiceRun]? = nil

    /// Which voice sings a PART of one line's text — an echo on just the
    /// last few words, rather than the whole line.
    ///
    /// ELI5: "Just this bit of the sentence is the echo."
    ///
    /// DETAILED (#2073): SPARSE, same reasoning and same default-`nil`
    /// additive posture as `voices` immediately above. `VoiceSpan.start`/
    /// `.end` are Unicode CODE-POINT offsets into the referenced line's text
    /// (CLAUDE.md rule #21 — never a byte or UTF-16 offset), `end`
    /// EXCLUSIVE; slice with `String` value semantics over `Character`/
    /// `Unicode.Scalar`, never `NSRange`/UTF-16 `.utf16` length, the day this
    /// is actually rendered. DEFERRED rendering, same as `voices`.
    public let voiceSpans: [VoiceSpan]? = nil
}

/// One contiguous run of lines sung by the same voice part(s) within a
/// single `SongComponent` — e.g. "lines 0 through 3, sung by the women".
///
/// ELI5: "From here to here, it's the women singing."
///
/// DETAILED (#2073): Mirrors `lyricLinesFoldVoiceRuns()`'s wire shape
/// (`.claude/vocal-parts-2073-plan.md` design pass 3 §4.1/§6.5). `from`/`to`
/// are 0-based, INCLUSIVE indexes into the OWNING component's `lines` array
/// — never a `tblLyricLines.Id` (that identity lives on `SongComponent
/// .lineIds`, rule #21); resolve a run's actual text via
/// `lines[from...to]`.
public struct VoiceRun: Sendable, Hashable, Codable {
    /// First line index (0-based, inclusive) this run covers.
    public let from: Int
    /// Last line index (0-based, inclusive) this run covers.
    public let to: Int
    /// The part(s) singing this run — almost always one; more than one
    /// models, e.g., a duet or two named singers sharing a line.
    public let parts: [VoiceRunPart]
}

/// One named voice part attached to a `VoiceRun` (or, without `enters`,
/// embedded in a `VoiceSpan`) — "the women", "echo", "Soloist: Sarah".
///
/// ELI5: A label for who's singing, plus whether it's an echo.
///
/// DETAILED (#2073): `kind` is the open, app-validated vocabulary key from
/// the web project's `includes/vocal_parts.php`'s `IHYMNS_VOCAL_PART_KINDS`
/// map — deliberately a plain `String`, not a Swift `enum`, for the SAME
/// open-vocabulary reasoning as `SongComponent.type`/`ExternalLink.category`
/// above (CLAUDE.md rule #20: a growable vocabulary is a validated
/// string/VARCHAR, never a closed enum/ENUM, so a server-added kind never
/// breaks decoding — it would with a Swift `enum` unless every case were
/// also `Optional`, which just re-creates the same problem one level up).
/// `bg` marks a background/echo part (a whole-LINE echo rides here; a
/// sub-line echo rides on `VoiceSpan` instead, never both at once).
/// `enters` is `true` only on the run where this part first appears
/// relative to the immediately-preceding run in the SAME component
/// (server-computed adjacency) — it is present on `VoiceRun.parts` but
/// ABSENT from the smaller shape embedded in `VoiceSpan.part` (design pass
/// 3 §6.5's own schema note: "VoiceSpan `part: VoiceRunPart-without-
/// enters`"), hence `Optional` here rather than a second, near-duplicate
/// struct — one Codable type serves both call sites.
public struct VoiceRunPart: Sendable, Hashable, Codable {
    /// The underlying `tblVocalParts.Id` — stable across saves; use THIS
    /// for chip identity when re-rendering, never the `label` text.
    public let id: Int
    /// Open vocabulary key, e.g. `"women"`, `"men"`, `"named-singer"`.
    public let kind: String
    /// Human-facing label, e.g. `"Women"`, or a named singer's display name.
    public let label: String
    /// `true` for a background/echo part.
    public let bg: Bool
    /// `true` only when this part is entering fresh in this run (vs.
    /// continuing from the previous run in the same component). `nil` on
    /// the trimmed copy nested inside `VoiceSpan.part` — see the type-level
    /// doc comment above.
    public let enters: Bool?
}

/// A sub-line echo/voice-part assignment — some voice singing only PART of
/// one line's text, e.g. just the last three words.
///
/// ELI5: "Just this bit of the line is a different voice."
///
/// DETAILED (#2073): Mirrors `lyricLinesFoldVoiceSpans()`'s wire shape.
/// `start`/`end` are Unicode CODE-POINT offsets into the REFERENCED line's
/// text (CLAUDE.md rule #21 — never a byte or UTF-16 offset), `end`
/// EXCLUSIVE. SPARSE on `SongComponent.voiceSpans` — present only when at
/// least one span exists anywhere in that component.
public struct VoiceSpan: Sendable, Hashable, Codable {
    /// 0-based index into the owning component's `lines` array — the line
    /// this span slices (same indexing convention as `VoiceRun.from`/`.to`).
    public let line: Int
    /// Start code-point offset into that line's text (inclusive).
    public let start: Int
    /// End code-point offset into that line's text (exclusive).
    public let end: Int
    /// The part singing this span. No `enters` here — that concept only
    /// applies to a whole run, not a sub-line slice.
    public let part: VoiceRunPart
}
