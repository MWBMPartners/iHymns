// ContractFixtures.swift
// IHTestFixtures
//
// ELI5: One tiny helper that finds and reads the real, live-recorded JSON
// files sitting right next to this Swift file, so `IHModelsTests` and
// `IHAPITests` don't each need their own copy of "how do I find a bundled
// test resource" boilerplate.
//
// DETAILED: `IHTestFixtures` is a plain (non-test) SwiftPM target whose
// `path:` is `Tests/Fixtures/` (see `Package.swift`) purely so its
// `resources:` declaration can bundle the three committed JSON fixtures as
// a proper SwiftPM resource bundle — accessed here via the
// compiler-synthesized `Bundle.module` (see SwiftPM's resources
// documentation: https://www.swift.org/documentation/package-manager/#resources).
// Both `IHModelsTests` and `IHAPITests` depend on this target and call
// through `ContractFixtures` rather than each hand-rolling their own
// `Bundle.module.url(forResource:...)` — the repo's modularity rule applied
// to test infrastructure.
import Foundation

/// Locates and loads the committed live-recorded API fixtures.
///
/// ELI5: "Give me the bytes of `songs_index.json`" (etc.) — throws a clear
/// error instead of a cryptic `nil` if a fixture ever goes missing.
public enum ContractFixtures {

    /// A fixture file genuinely wasn't found in the resource bundle — this
    /// should only ever happen if a fixture is renamed/removed without
    /// updating every call site, never at normal test runtime.
    public struct MissingFixture: Error, CustomStringConvertible {
        let name: String
        public var description: String { "Contract fixture \"\(name).json\" was not found in IHTestFixtures' resource bundle." }
    }

    /// Reads `Tests/Fixtures/songs_index.json` — a TRIMMED (84-row) but
    /// otherwise byte-for-byte real `?action=songs_index` response,
    /// including every one of the live catalogue's 10 rows whose `id`
    /// doesn't match `SongID`'s shape (see `README.md` alongside this file).
    public static func songsIndex() throws -> Data {
        try data(named: "songs_index")
    }

    /// Reads `Tests/Fixtures/song_detail.json` — the full, untrimmed real
    /// `?action=song_detail&id=MP-0031` response.
    public static func songDetail() throws -> Data {
        try data(named: "song_detail")
    }

    /// Reads `Tests/Fixtures/songbooks.json` — the full, untrimmed real
    /// `?action=songbooks` response (all 54 songbooks on `dev`).
    public static func songbooks() throws -> Data {
        try data(named: "songbooks")
    }

    /// Reads `Tests/Fixtures/song_links.json` — a real (but empty)
    /// `?action=song_links&id=MP-0031` response (#180). See `README.md`
    /// alongside this file for why an empty group is the honest live
    /// answer today.
    public static func songLinks() throws -> Data {
        try data(named: "song_links")
    }

    /// Reads `Tests/Fixtures/related_songs.json` — the full, real
    /// `?action=related_songs&id=MP-0031` response (#180): 10 genuinely
    /// related songs with human-readable match reasons.
    public static func relatedSongs() throws -> Data {
        try data(named: "related_songs")
    }

    /// Shared lookup: resolves `<name>.json` in this target's resource
    /// bundle and reads it into `Data`.
    private static func data(named name: String) throws -> Data {
        guard let url = Bundle.module.url(forResource: name, withExtension: "json") else {
            throw MissingFixture(name: name)
        }
        return try Data(contentsOf: url)
    }
}
