// ProfileSchemaTests.swift
// IHModelsTests
//
// ELI5: Checks the app can read its bundled copy of the server's list of shared
// settings, and that "is this value allowed for this setting?" gives the same
// answers the server would.
//
// DETAILED: PR 3b step I4 (#2180). The bundled file is the snapshot of
// `includes/profile_registry.php`; `tests/test-profile-registry-lockstep.js`
// (Node, runs in the sandbox) proves the file equals the server's list. These
// tests (run in CI on macOS) prove the SWIFT side reads it and judges values
// the way `profileValidateSpec()` does for the scalar kinds.
import Foundation
import Testing
@testable import IHModels

@Suite("ProfileSchema (I4)")
struct ProfileSchemaTests {

    @Test("The bundled snapshot loads and holds the settings the app maps")
    func bundledLoads() throws {
        let schema = try ProfileSchema.bundled()
        #expect(schema.schemaVersion >= 1)
        for id in ["appearance.theme", "appearance.cvd", "reading.textScale", "reading.showChords", "reading.dyslexia"] {
            let entry = try #require(schema.entry(id: id))
            #expect(entry.isForApple)
            #expect(entry.sync == "profile_sync")
            #expect(entry.scope == "account")
        }
        #expect(schema.entry(id: "no.such.setting") == nil)
    }

    @Test("Device-scoped and web-only entries are not for Apple")
    func appleFlag() throws {
        let schema = try ProfileSchema.bundled()
        #expect(try #require(schema.entry(id: "appearance.fontSize")).isForApple == false)
        #expect(try #require(schema.entry(id: "device.midiPedal")).isForApple == false)
        #expect(try #require(schema.entry(id: "appearance.reduceMotion")).isForApple) // platform "all"
    }

    @Test("An enum accepts only its allowed words (and the none word is not allowed for colour vision)")
    func enumValidation() throws {
        let schema = try ProfileSchema.bundled()
        let theme = try #require(schema.entry(id: "appearance.theme"))
        #expect(theme.validate(.string("dark")))
        #expect(theme.validate(.string("high-contrast")))
        #expect(!theme.validate(.string("neon")))
        #expect(!theme.validate(.bool(true)))
        let cvd = try #require(schema.entry(id: "appearance.cvd"))
        #expect(cvd.validate(.string("deuteranopia")))
        #expect(!cvd.validate(.string("none")))
    }

    @Test("A float accepts a whole or fractional number inside its range and nothing else")
    func floatValidation() throws {
        let scale = try #require(try ProfileSchema.bundled().entry(id: "reading.textScale"))
        #expect(scale.validate(.double(1.25)))
        #expect(scale.validate(.int(1)))
        #expect(scale.validate(.double(0.5)))
        #expect(scale.validate(.double(5)))
        #expect(!scale.validate(.double(0.49)))
        #expect(!scale.validate(.double(5.01)))
        #expect(!scale.validate(.double(.infinity)))
        #expect(!scale.validate(.string("1")))
    }

    @Test("A bool accepts only a bool")
    func boolValidation() throws {
        let chords = try #require(try ProfileSchema.bundled().entry(id: "reading.showChords"))
        #expect(chords.validate(.bool(false)))
        #expect(!chords.validate(.int(1)))
        #expect(!chords.validate(.string("true")))
    }

    @Test("An int honours its range and step")
    func intValidation() throws {
        let size = try #require(try ProfileSchema.bundled().entry(id: "appearance.fontSize"))
        #expect(size.validate(.int(14)))
        #expect(size.validate(.int(18)))
        #expect(!size.validate(.int(15)))   // off the step of 2
        #expect(!size.validate(.int(30)))
        #expect(!size.validate(.double(18)))
    }

    @Test("A kind the app does not map (list, map, json) is never accepted")
    func unmappedKindsRefused() throws {
        let schema = try ProfileSchema.bundled()
        let display = try #require(schema.entry(id: "reading.display"))
        #expect(display.type == "json")
        #expect(!display.validate(.string("{}")))
        let recent = try #require(schema.entry(id: "library.recentSongbooks"))
        #expect(!recent.validate(.string("MP")))
    }

    @Test("1 and 1.0 are one value")
    func numbersCompareByValue() {
        #expect(ProfileValue.int(1) == ProfileValue.double(1.0))
        #expect(ProfileValue.int(1) != ProfileValue.double(1.5))
        #expect(ProfileValue.bool(true) != ProfileValue.int(1))
        #expect(ProfileValue.string("a") == ProfileValue.string("a"))
    }

    @Test("Scalars decode from JSON, and anything else is refused")
    func decodesScalars() throws {
        let decoded = try JSONDecoder().decode([ProfileValue].self, from: Data(#"[true, 3, 1.5, "dark"]"#.utf8))
        #expect(decoded == [.bool(true), .int(3), .double(1.5), .string("dark")])
        #expect(throws: DecodingError.self) { _ = try JSONDecoder().decode(ProfileValue.self, from: Data(#"{"a":1}"#.utf8)) }
        #expect(throws: DecodingError.self) { _ = try JSONDecoder().decode(ProfileValue.self, from: Data("null".utf8)) }
    }
}
