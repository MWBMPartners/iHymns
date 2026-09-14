import Foundation
import Testing
@testable import IHModels

/// Does a song section actually keep what the server sends it?
///
/// ELI5: the server tells us who sings each line, and whether a curator gave the
/// section its own name. These tests check we still have that information after
/// reading the server's answer, rather than quietly dropping it.
///
/// WHY THIS FILE EXISTS. On 2026-09-05 the singing-parts work added two values to
/// `SongComponent` and its own comment said it was "DECODE-ONLY: it teaches this
/// struct to accept the two keys". It did not. They were written as:
///
///     public let voices: [VoiceRun]? = nil
///
/// A constant that is already given a value is one Swift can never fill in from
/// the server's answer, so every singing part arriving from the server was thrown
/// away the moment it was read. The compiler said so, in as many words —
/// "immutable property will not be decoded because it is declared with an initial
/// value which cannot be overwritten" — and printed that warning 102 times in the
/// build that was then cited as proof the app was healthy. Warnings do not fail a
/// build, so a green tick was read as working code.
///
/// Nothing caught it because **no fixture anywhere contained a `voices` key**, so
/// there was no test that could have noticed. That is the gap this file closes:
/// it feeds the struct the real shape the server sends and insists the values
/// survive.
///
/// Two related notes worth keeping:
///
///  - A song saved for reading offline is stored by re-encoding this struct, not
///    by keeping the server's original bytes. So anything this struct drops is
///    gone from the saved copy permanently, and songs saved before the fix need
///    saving again.
///  - `label` is DISPLAY ONLY (repository rule #45). `type` remains the thing that
///    decides how a section behaves and how it is exported. Never work out what
///    kind of section it is from the name a curator typed.
@Suite("SongComponent keeps what the server sends")
struct SongComponentDecodingTests {

    /// (Corrected 2026-09-14.) The echo's `part` used to carry `"enters": false`, but
    /// the server never sends `enters` for a within-line span — only `id`, `kind`,
    /// `label` and `bg` (`lyric_lines_read.php`, the voiceSpans block). A fixture
    /// with a key the server never sends cannot catch that key being made required,
    /// which would make every real song with an echo fail to load. Removed.
    ///
    /// Exactly the shape `includes/lyric_lines_read.php` produces for a verse the
    /// women start and the men answer, with an echo inside the second line.
    private static let withVoices = Data("""
    {
      "type": "verse",
      "number": 1,
      "lines": ["Praise to the Lord", "the Almighty"],
      "chords": null,
      "language": "en",
      "lineIds": [101, 102],
      "lineLanguages": null,
      "label": "Kyrie",
      "voices": [
        { "from": 0, "to": 0, "parts": [
            { "id": 1, "kind": "women", "label": "Women", "bg": false, "enters": true } ] },
        { "from": 1, "to": 1, "parts": [
            { "id": 2, "kind": "men", "label": "Men", "bg": false, "enters": true } ] }
      ],
      "voiceSpans": [
        { "line": 1, "start": 4, "end": 11,
          "part": { "id": 3, "kind": "echo", "label": "Echo", "bg": true } }
      ]
    }
    """.utf8)

    /// An ordinary section, with none of the newer keys present at all. The server
    /// leaves them out unless there is something to say, so this is the common case.
    private static let plain = Data("""
    {
      "type": "chorus",
      "number": 0,
      "lines": ["O praise him"],
      "chords": null,
      "language": null,
      "lineIds": [201],
      "lineLanguages": null
    }
    """.utf8)

    @Test("who sings each run survives being read")
    func voicesSurvive() throws {
        let component = try JSONDecoder().decode(SongComponent.self, from: Self.withVoices)
        // Before the fix this was nil, and that is the whole point of the test.
        let voices = try #require(component.voices, "the singing parts were dropped while reading the server's answer")
        #expect(voices.count == 2)
        #expect(voices[0].parts.first?.kind == "women")
        #expect(voices[1].parts.first?.kind == "men")
        #expect(voices[0].from == 0 && voices[0].to == 0)
    }

    @Test("an echo inside a line survives being read")
    func spansSurvive() throws {
        let component = try JSONDecoder().decode(SongComponent.self, from: Self.withVoices)
        let spans = try #require(component.voiceSpans, "the within-line echo was dropped while reading the server's answer")
        #expect(spans.count == 1)
        #expect(spans[0].line == 1)
        #expect(spans[0].start == 4 && spans[0].end == 11)
        #expect(spans[0].part.kind == "echo")
        #expect(spans[0].part.bg == true)
    }

    @Test("a curator's own name for the section survives being read")
    func labelSurvives() throws {
        let component = try JSONDecoder().decode(SongComponent.self, from: Self.withVoices)
        #expect(component.label == "Kyrie")
    }

    @Test("a section with none of the newer keys still reads perfectly")
    func plainStillWorks() throws {
        let component = try JSONDecoder().decode(SongComponent.self, from: Self.plain)
        #expect(component.type == "chorus")
        #expect(component.lines == ["O praise him"])
        #expect(component.voices == nil)
        #expect(component.voiceSpans == nil)
        #expect(component.label == nil)
    }

    /// The saved-for-offline copy is made by re-encoding this struct, so anything
    /// the struct drops is lost from the saved copy for good. This proves a round
    /// trip keeps everything, which is what makes an offline song trustworthy.
    @Test("saving and reading back keeps the singing parts")
    func roundTripKeepsEverything() throws {
        let original = try JSONDecoder().decode(SongComponent.self, from: Self.withVoices)
        let saved    = try JSONEncoder().encode(original)
        let reloaded = try JSONDecoder().decode(SongComponent.self, from: saved)
        #expect(reloaded.voices?.count == 2)
        #expect(reloaded.voiceSpans?.count == 1)
        #expect(reloaded.label == "Kyrie")
        #expect(reloaded == original)
    }
}
