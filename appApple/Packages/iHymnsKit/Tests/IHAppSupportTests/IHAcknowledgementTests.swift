// IHAcknowledgementTests.swift
// IHAppSupportTests
//
// ELI5: Checks that the "who else's code is in here" list is complete and
// correctly labelled — GRDB.swift really is listed as MIT, and OpenDyslexic
// really is attributed to its author with its Reserved Font Name clause
// noted, now that the font ships bundled with the app.
//
// #190 (Apple Phase 1 — help/legal/acknowledgements). This is the "pure
// logic (e.g. the acknowledgements list builder)" test coverage that
// task's brief explicitly calls out.
import Testing
@testable import IHAppSupport

@Suite("IHAcknowledgements")
struct IHAcknowledgementTests {

    @Test("Lists exactly the third-party components this app actually ships")
    func listsExpectedComponents() {
        let names = IHAcknowledgements.all().map(\.name)
        #expect(names == ["GRDB.swift", "OpenDyslexic"])
    }

    @Test("GRDB.swift is correctly attributed as MIT")
    func grdbIsMIT() {
        let grdb = IHAcknowledgements.all().first { $0.name == "GRDB.swift" }
        #expect(grdb?.licenseName == "MIT License")
    }

    @Test("OpenDyslexic is SIL OFL 1.1 and correctly attributes Abbie Gonzalez's Reserved Font Name (#1412)")
    func openDyslexicIsBundledOFL() {
        let openDyslexic = IHAcknowledgements.all().first { $0.name == "OpenDyslexic" }
        #expect(openDyslexic?.licenseName == "SIL Open Font License 1.1")
        #expect(openDyslexic?.note?.contains("Abbie Gonzalez") == true)
        #expect(openDyslexic?.note?.contains("Reserved Font Name") == true)
        // Must no longer claim the pending/not-bundled status now that the
        // OTFs actually ship — a stale claim here would mislead the user.
        #expect(openDyslexic?.note?.contains("FONT-PENDING") == false)
    }

    @Test("Every entry has a non-empty id, name, and licence — nothing malformed slips through")
    func everyEntryIsWellFormed() {
        for acknowledgement in IHAcknowledgements.all() {
            #expect(!acknowledgement.id.isEmpty)
            #expect(!acknowledgement.name.isEmpty)
            #expect(!acknowledgement.licenseName.isEmpty)
        }
    }
}
