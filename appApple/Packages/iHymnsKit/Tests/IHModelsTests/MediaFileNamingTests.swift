// MediaFileNamingTests.swift
// IHModelsTests
//
// ELI5: Checks the "make this file name safe to save to disk" helper — a
// normal name is kept, but anything with sneaky "/" or ".." is neutralised so
// a cached media file can never land outside its folder.
//
// DETAILED: Covers `MediaFileNaming.sanitizedFileName` (#1440) including the
// Phase-1-review regression: a pathological "/" input used to survive as a
// bare separator and make the file write silently fail.
import Testing
@testable import IHModels

@Suite("MediaFileNaming")
struct MediaFileNamingTests {

    @Test("A normal file name is kept verbatim")
    func keepsNormalName() {
        #expect(MediaFileNaming.sanitizedFileName("amazing-grace.mid") == "amazing-grace.mid")
    }

    @Test("Path separators and traversal sequences are stripped to a safe leaf")
    func stripsTraversal() {
        // Each result must be a single leaf: no "/", never a bare "." or "..".
        for hostile in ["/", "//", "..", "../../etc/passwd", "/etc/passwd", "a/../b", "."] {
            let safe = MediaFileNaming.sanitizedFileName(hostile)
            #expect(!safe.contains("/"), "\(hostile) → \(safe) still has '/'")
            #expect(safe != "..", "\(hostile) → returned '..'")
            #expect(safe != ".", "\(hostile) → returned '.'")
            #expect(!safe.isEmpty, "\(hostile) → empty leaf")
        }
    }

    @Test("A pathological '/' input falls back to the neutral default (review fix)")
    func slashFallsBackToDefault() {
        #expect(MediaFileNaming.sanitizedFileName("/") == "download")
        #expect(MediaFileNaming.sanitizedFileName("") == "download")
    }
}
