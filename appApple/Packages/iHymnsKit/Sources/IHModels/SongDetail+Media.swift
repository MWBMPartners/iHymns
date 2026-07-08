// SongDetail+Media.swift
// IHModels
//
// ELI5: A quick way to ask a song "do you have a recording?", "do you have
// sheet music?", "do you have a MIDI or MusicXML file?" — without every
// screen that asks having to know the exact spelling of `"sheet-music"` vs
// `"sheetmusic"` vs `"sheet_music"` itself.
//
// DETAILED: #184 (Apple Phase 1: audio/MIDI/PDF/MusicXML media). The dev-API
// survey behind this task confirmed `SongDetail.media: [SongMediaAsset]`
// (`SongDetail.swift`) already mirrors `tblSongMedia` field-for-field —
// `SongMediaAsset.kind` is the open-vocabulary string the PHP backend writes
// (`SongData::_songMediaMap()`, wire values `"audio" | "sheet-music" |
// "midi" | "musicxml"`, confirmed by reading `includes/pages/song.php`'s own
// `mediaByKind` map, the same PHP the web PWA renders from). Centralising
// those four literal strings HERE — instead of every future view/view-model
// re-typing `"sheet-music"` and risking a typo that silently hides a real
// asset — is the same "one shared module, never copy-paste a magic string"
// modularity rule `.claude/CLAUDE.md` states for the web app, applied
// identically to this native client.
//
// `hasAudio`/`hasSheetMusic` (the two dedicated `Bool` columns already on
// `SongDetail`) are index-level hints only — cheap denormalised flags the
// slim `SongSummary` list also carries. THIS file's accessors are what
// screens should actually use once they have a full `SongDetail` in hand:
// they read the real `media` array, so they never disagree with what
// `hasAudio`/`hasSheetMusic` claim (a theoretical drift between the
// denormalised flag and the real row set is exactly the kind of bug reading
// the authoritative array sidesteps).
import Foundation

extension SongMediaAsset {
    /// The open vocabulary of `kind` values the backend actually emits
    /// today (`SongData::_songMediaMap()`). Kept as `String` constants
    /// (matching `SongMediaAsset.kind`'s own non-`enum` typing) rather than
    /// a Swift `enum` — the backend can grow this vocabulary without a
    /// migration (schema.sql's own comment lists further future kinds like
    /// `"notation-source"`/generic `"pdf"`), and an `enum` would need a
    /// `.other(String)` escape hatch anyway to stay forward-compatible; a
    /// namespace of `String` constants gives the same "don't hand-type the
    /// literal" safety with none of that ceremony.
    public enum Kind {
        public static let audio = "audio"
        public static let sheetMusic = "sheet-music"
        public static let midi = "midi"
        public static let musicXml = "musicxml"
    }
}

extension SongDetail {
    /// This song's single audio recording, if it has one — `nil` when
    /// `media` carries no `"audio"`-kind row (mirrors `hasAudio == false`,
    /// though this is the authoritative check; see this file's header).
    ///
    /// ELI5: "The MP3 for this song, if there is one."
    ///
    /// DETAILED: `first`, not a full filter — `tblSongMedia` allows several
    /// rows per song per kind in principle (curators could attach more than
    /// one recording), but every live sample this task's survey found had at
    /// most one `"audio"` row per song, and a single play/pause control (the
    /// #184 brief) only ever needs one URL to point at. `sortOrder` is what
    /// the backend uses to rank multiple rows of the same kind, so `media`
    /// is already in the right order for `first` to pick the curator-
    /// intended one.
    public var audioAsset: SongMediaAsset? {
        media.first { $0.kind == SongMediaAsset.Kind.audio }
    }

    /// This song's sheet-music file, if it has one — same "first, already
    /// curator-ordered" reasoning as `audioAsset` above.
    public var sheetMusicAsset: SongMediaAsset? {
        media.first { $0.kind == SongMediaAsset.Kind.sheetMusic }
    }

    /// Every attached MIDI file — plural (unlike `audioAsset`/
    /// `sheetMusicAsset`) because a curator plausibly attaches more than one
    /// MIDI arrangement (e.g. one per verse tempo) for a single hymn, and
    /// #184's scope for this kind is "list them, let the user download/share
    /// whichever they want" rather than picking one automatically.
    public var midiAssets: [SongMediaAsset] {
        media.filter { $0.kind == SongMediaAsset.Kind.midi }
    }

    /// Every attached MusicXML file — same plural reasoning as
    /// `midiAssets` above.
    public var musicXmlAssets: [SongMediaAsset] {
        media.filter { $0.kind == SongMediaAsset.Kind.musicXml }
    }
}
