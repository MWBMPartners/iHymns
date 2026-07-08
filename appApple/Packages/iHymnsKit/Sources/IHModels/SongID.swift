// SongID.swift
// IHModels
//
// ELI5: A "SongID" is the short code printed next to a hymn, like `MP-1008`.
// This file teaches Swift how to read that code and pull out its two parts —
// the songbook's letters and the hymn's number — so the rest of the app
// never has to hand-roll that parsing itself.
//
// DETAILED: `tblSongs.SongId` on the web backend is built from
// `tblSongbooks.Abbreviation` (an alphanumeric prefix, e.g. `MP`, `SDAH`)
// plus a dash plus the song's number within that book (e.g. `MP-1008`). This
// is the SAME rule documented in the repo's `.claude/CLAUDE.md` rule #27
// ("Songbook `DisplayAbbr` is display-only; `Abbreviation` is the SongId
// prefix") and consumed by the PWA's `normalizeSongId` router, the OG-image
// generator, and the `related_songs`/`remove_favorite`/`song_view` API
// validators. The native app must parse this identically so a Universal
// Link like `https://ihymns.app/song/MP-1008` round-trips to the exact same
// song the web/PWA would resolve. See MDN on regex named capture groups
// (https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_Expressions/Named_capturing_group)
// and the Swift Regex docs
// (https://developer.apple.com/documentation/swift/regexcomponent) for the
// underlying pattern-matching approach mirrored here with `NSRegularExpression`
// (kept for cross-platform/back-compat parsing clarity over the newer
// `Regex` literal type, which is equivalent here but this reads plainly to
// non-Swift-regex-fluent maintainers).
import Foundation

/// A strongly-typed wrapper around an iHymns song identifier, e.g. `MP-1008`.
///
/// ELI5: Instead of passing a plain `String` around (which could be
/// literally anything, including garbage), `SongID` guarantees — at
/// construction time — that the value really does look like
/// `<letters>-<digits>`. If it doesn't, construction simply fails (returns
/// `nil`) instead of the app discovering the problem much later deep inside
/// a network call or a SwiftUI view.
///
/// DETAILED: This is the "parse, don't validate" pattern (see Alexis King's
/// widely-cited essay, https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/):
/// rather than accepting a raw `String` deep into the call stack and
/// re-validating its shape at every layer, we validate ONCE at the boundary
/// (usually when decoding a `song_detail`/`songs_index` API response, or
/// parsing a Universal Link — strategy §1.7) and thereafter carry a value
/// whose *type* proves the invariant already held. `Sendable` because
/// `SongID` values cross actor boundaries constantly (API responses land on
/// a background `actor APIClient`, then get displayed by a `@MainActor`
/// SwiftUI view) — Swift 6 strict concurrency (strategy §1.3) requires every
/// cross-actor value to be provably safe to share, and an immutable value
/// type of `Sendable` primitives (`String`) satisfies that automatically.
public struct SongID: Sendable, Hashable, Codable, CustomStringConvertible {

    /// The songbook prefix, e.g. `"MP"` out of `MP-1008`.
    ///
    /// ELI5: The letters part — which hymn book this song lives in.
    ///
    /// DETAILED: This mirrors `tblSongbooks.Abbreviation` exactly (never
    /// `DisplayAbbr`, which is a display-only relabelling — CLAUDE.md #27).
    /// Always uppercase-normalized on parse (see `init(rawValue:)`) because
    /// the web side treats the prefix case-insensitively when matching a
    /// songbook, and we want `SongID` equality/hashing to reflect "same
    /// song" rather than "same byte-for-byte string."
    public let songbookAbbreviation: String

    /// The song's number within its songbook, e.g. `1008` out of `MP-1008`.
    ///
    /// ELI5: The number part — which hymn in the book.
    ///
    /// DETAILED: Kept as `Int` (not `String`) so leading zeros never become
    /// a false-inequality trap (`"008"` vs `"8"`) and so downstream code can
    /// do numeric sorting/formatting without a re-parse.
    public let number: Int

    /// The exact original string this `SongID` was parsed from, e.g.
    /// `"MP-1008"`.
    ///
    /// ELI5: Keep the original text around too, so we can always show/send
    /// back exactly what came in.
    ///
    /// DETAILED: We store this rather than reconstructing
    /// `"\(songbookAbbreviation)-\(number)"` on demand, because
    /// reconstruction could theoretically differ from the input (e.g. if a
    /// future relaxation of the parsing rule tolerates lowercase or extra
    /// whitespace) — API round-trips (send this exact string back to
    /// `?action=related_songs&id=`) must never silently rewrite the id.
    public let rawValue: String

    /// The compiled pattern for a valid SongID: one-or-more ASCII letters,
    /// a literal hyphen, one-or-more ASCII digits — anchored at both ends
    /// so `"MP-1008-extra"` or `"xMP-1008"` are correctly rejected.
    ///
    /// ELI5: The exact shape we're willing to accept.
    ///
    /// DETAILED: `NSRegularExpression` (Foundation) is used instead of the
    /// Swift 5.7+ `Regex` literal type purely for readability to future
    /// maintainers unfamiliar with Swift's newer regex literal syntax; the
    /// two are functionally interchangeable for this simple anchored
    /// pattern. Compiled once (`static let`) rather than per-call, since
    /// `NSRegularExpression` compilation is measurably more expensive than
    /// matching (Apple's docs recommend caching compiled patterns:
    /// https://developer.apple.com/documentation/foundation/nsregularexpression).
    private static let pattern: NSRegularExpression = {
        // swiftlint:disable:next force_try
        try! NSRegularExpression(pattern: "^([A-Za-z]+)-([0-9]+)$")
    }()

    /// Attempts to parse `rawValue` as a `<letters>-<digits>` SongID.
    ///
    /// - Parameter rawValue: A candidate song identifier, e.g. `"MP-1008"`.
    /// - Returns: `nil` if `rawValue` does not match the required shape.
    ///
    /// ELI5: Give it a string; if it looks like a real SongID you get one
    /// back, otherwise you get nothing (so calling code MUST handle the
    /// "not a valid id" case rather than assuming success).
    ///
    /// DETAILED: A failable initializer (`init?`) rather than a
    /// throwing one because "not a SongID" is an ordinary, expected outcome
    /// here (e.g. a malformed deep link) — not an exceptional error needing
    /// a stack of context (that's what `throws` is for). Callers that DO
    /// need rich error context (e.g. the deep-link router logging why a URL
    /// was rejected) can build that on top of this `nil` case.
    public init?(rawValue: String) {
        let range = NSRange(rawValue.startIndex..<rawValue.endIndex, in: rawValue)
        guard
            let match = Self.pattern.firstMatch(in: rawValue, range: range),
            let abbrRange = Range(match.range(at: 1), in: rawValue),
            let numberRange = Range(match.range(at: 2), in: rawValue),
            let parsedNumber = Int(rawValue[numberRange])
        else {
            return nil
        }

        // Normalize the songbook prefix to uppercase (see doc comment on
        // `songbookAbbreviation` above) while preserving `rawValue` as
        // originally supplied.
        self.songbookAbbreviation = rawValue[abbrRange].uppercased()
        self.number = parsedNumber
        self.rawValue = rawValue
    }

    /// Convenience initializer for building a `SongID` from known-good
    /// parts (e.g. constructing a link from a `SongSummary` already fetched
    /// from the API, where the shape is guaranteed by the server).
    ///
    /// ELI5: If you already know the book letters and the number, skip the
    /// text-parsing step and build the id directly.
    ///
    /// DETAILED: Still funnels through the same normalization as the
    /// string-parsing path (uppercased abbreviation) so `SongID(songbookAbbreviation:number:)`
    /// and `SongID(rawValue:)` always produce equal values for the "same"
    /// song, regardless of which constructor was used.
    public init(songbookAbbreviation: String, number: Int) {
        // Unlike `init?(rawValue:)` (regex-validated) this initializer is
        // non-failable, so it must not let an unvalidated abbreviation put a
        // path separator / traversal sequence into `rawValue` — the type's
        // load-bearing invariant is that `rawValue` is safe to use as a
        // filesystem path component (e.g. `OfflineStore` media cache). Keep
        // only ASCII alphanumerics; every real songbook abbreviation is
        // already `[A-Za-z0-9]` (rule #27), so this is a no-op for legit
        // values and neutralizes a hostile `"../.."` at construction (Phase-1
        // review finding — latent, not currently reachable, closed anyway).
        let safeAbbr = songbookAbbreviation.uppercased().filter { $0.isASCII && ($0.isLetter || $0.isNumber) }
        self.songbookAbbreviation = safeAbbr
        self.number = number
        self.rawValue = "\(safeAbbr)-\(number)"
    }

    /// `CustomStringConvertible` conformance — printing a `SongID` shows the
    /// canonical `<letters>-<digits>` string, matching web URLs/OG-images.
    public var description: String { rawValue }

    // MARK: Codable
    //
    // ELI5: Teach Swift how to turn a `SongID` into JSON text and back,
    // using the plain string form (not a nested `{ "abbr": ..., "number": ... }`
    // object), because that's the shape the API sends/expects.
    //
    // DETAILED: A custom `Codable` conformance (rather than the
    // synthesized one, which would encode the three stored properties as an
    // object) is required because every iHymns API payload represents a
    // SongId as a single JSON string (e.g. `"id": "MP-1008"`), not a
    // structured object. Decoding re-runs the SAME validation as
    // `init?(rawValue:)` — a `SongID` can never exist in an invalid shape,
    // whether constructed from a literal, a deep link, or a decoded API
    // response.

    private struct DecodingFailure: Error, CustomStringConvertible {
        let offendingValue: String
        var description: String { "\"\(offendingValue)\" is not a valid SongID (expected <letters>-<digits>)." }
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        let string = try container.decode(String.self)
        guard let parsed = SongID(rawValue: string) else {
            throw DecodingFailure(offendingValue: string)
        }
        self = parsed
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(rawValue)
    }
}
