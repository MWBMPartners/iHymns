// MediaCacheRevalidationPolicy.swift
// IHModels
//
// ELI5: A tiny rulebook, with no moving parts of its own, that answers one
// question: "we cached this file a while ago — should we bother checking if
// the server's version has changed, and if we DO check, has it actually
// changed?" It never touches the network or the disk itself — it just does
// the arithmetic, so both the code that actually re-downloads files
// (`IHFeatures`) and a plain unit test can ask it the same question and get
// the same answer.
//
// DETAILED: #1450 ("cached-media staleness has no invalidation story").
// #1440 (offline media caching) cached a song's attached audio/sheet-music/
// MIDI/MusicXML files forever, with no mechanism that ever re-checks whether
// the SERVER-side file behind that same `mediaAssetId` has since changed
// (a curator re-uploading a corrected scan, a re-mastered recording, ...).
//
// STRATEGY CHOSEN: option (b) from this issue's own "Rough approach" —
// "a content-hash/version keyed cache with a TTL" — using
// `SongMediaAsset.sizeBytes` as the lightweight "did the content change?"
// signal (every `song_detail` fetch already returns it, so checking it
// costs NO extra network request) gated behind a re-check TTL
// (`defaultStaleAfter`). Two alternatives from the issue were deliberately
// NOT chosen:
//   (a) ETag/Last-Modified conditional GET — would need `/song-media/<id>`
//       to start emitting those headers server-side, which is backend work
//       outside this native-app task's scope (the issue's own dependency
//       note says exactly this). Filed as a `for consideration` follow-up
//       if a real byte-for-byte content signal is ever wanted instead of
//       the sizeBytes proxy.
//   (c) bare "max-age re-validation" with NO content signal at all — would
//       mean blindly re-downloading every cached asset once `staleAfter`
//       elapses, even when nothing changed; comparing `sizeBytes` first
//       (free, already in hand) avoids that wasted re-download in the
//       overwhelmingly common case where the file simply hasn't changed.
//
// WHY A TTL AT ALL, when the sizeBytes check itself is free? Because the
// call site (`IHFeatures.MediaCacheRevalidator`, triggered from
// `SongDetailViewModel.load()`'s successful primary fetch per this issue's
// own suggestion) could otherwise run this decision on EVERY song-screen
// visit, including several within the same minute — the TTL means a
// `lastValidatedAt` bump only happens roughly once per `staleAfter` window,
// which is both cheap bookkeeping (`markCachedMediaValidated` still writes
// even on `.stillFresh`, but only after the window elapses) and a sane
// mental model ("we last confirmed this is current as of…").
//
// SAFETY: this type makes NO decision to delete anything — `.staleNeedsRefetch`
// only ever tells a caller "go download a REPLACEMENT"; the actual
// replace-or-keep-the-old-copy call happens at the I/O layer
// (`OfflineStore.cacheMedia(songId:asset:data:)`'s existing
// insert-on-conflict-replace upsert), and only ever fires AFTER a new
// download has fully succeeded — never before, and never on a failed fetch.
// This is exactly what makes the whole scheme safe offline: a network
// failure while attempting a re-check simply leaves the existing cached
// file untouched, because THIS type is never even consulted for anything
// beyond its return value — no I/O, no side effects, testable with zero
// mocking.
//
// #1460 UPDATE — the "(a) ETag/Last-Modified conditional GET" alternative
// this header originally deferred is now IMPLEMENTED client-side, using
// #1452's shipped backend support. `isDueForRecheck(lastValidatedAt:...)`
// is `decide(...)`'s own TTL gate, extracted so `IHFeatures
// .MediaCacheRevalidator` can apply the IDENTICAL "don't even bother
// checking yet" window BEFORE choosing which comparison mechanism to use —
// a real conditional GET is still a real network request (cheap, but not
// free), so it deserves the same gate the sizeBytes path already had.
// `decideConditional(outcome:)` maps a REAL 304/200 server answer to the
// SAME three-case `MediaCacheRevalidationDecision` `decide(...)` returns,
// so the revalidator treats both mechanisms identically once a verdict is
// in hand. `decide(...)` itself is UNCHANGED (still the sizeBytes+TTL
// heuristic) — it remains the fallback `MediaCacheRevalidator` uses for any
// asset that hasn't picked up a stored ETag yet (an older cached row, or
// one written before the #1460 migration), per this feature's own "keep
// the heuristic as the fallback" instruction.
import Foundation

/// What `MediaCacheRevalidationPolicy.decide(...)` recommends doing with one
/// cached media asset.
///
/// ELI5: "Leave it alone for now," "still good, just note that we checked,"
/// or "go get a fresh copy."
public enum MediaCacheRevalidationDecision: Sendable, Equatable {
    /// Still within the "don't bother re-checking yet" window
    /// (`staleAfter` hasn't elapsed since `lastValidatedAt`) — do nothing at
    /// all, not even a bookkeeping write.
    case skipTooRecent

    /// Past the re-check window, but the server's own `sizeBytes` still
    /// matches what's cached — nothing to re-download; the caller should
    /// still bump `lastValidatedAt` so the NEXT check's window starts fresh
    /// from now, not from whenever this file was first cached.
    case stillFresh

    /// Past the re-check window AND the server's `sizeBytes` has changed —
    /// the cached copy is stale; the caller should attempt a re-download and
    /// replace the cached file (see this file's header for why a FAILED
    /// re-download must never delete the existing, still-serviceable copy).
    case staleNeedsRefetch
}

/// What a real conditional media GET (#1460) came back with — the
/// server's own, authoritative answer, strictly stronger than the
/// `sizeBytes` proxy `decide(...)` compares (a same-size content
/// replacement is invisible to `sizeBytes`, but never to a real ETag).
public enum MediaCacheConditionalOutcome: Sendable, Equatable {
    /// A real HTTP `304 Not Modified` — the cached bytes are still exactly
    /// current.
    case notModified

    /// A real HTTP `200` with a fresh body — the cached bytes are stale.
    case replaced
}

/// Pure, side-effect-free staleness arithmetic for one cached media asset —
/// see this file's header for the full "why sizeBytes + a TTL, not
/// ETag/Last-Modified" reasoning.
public enum MediaCacheRevalidationPolicy {
    /// How long a cached asset is trusted without even checking the
    /// server's metadata again — 24 hours. Worship media (recordings,
    /// scans) changes rarely; a full day between re-checks is generous
    /// headroom against "checking on literally every song open" while still
    /// catching a curator's correction within about a day of the next time
    /// the user actually opens that song while online (this never runs on
    /// a timer in the background — see `MediaCacheRevalidator`'s header).
    public static let defaultStaleAfter: TimeInterval = 60 * 60 * 24

    /// Decides what to do with one cached asset, given its own cached
    /// bookkeeping and the FRESH metadata a just-succeeded `song_detail`
    /// fetch already carries for the same asset.
    ///
    /// ELI5: "Here's what we have cached, here's what the server says right
    /// now, here's when we last checked — what should we do?"
    ///
    /// - Parameters:
    ///   - cachedSizeBytes: `CachedMediaInfo.sizeBytes` — the size of the
    ///     bytes actually on disk right now.
    ///   - serverSizeBytes: `SongMediaAsset.sizeBytes` from a FRESH network
    ///     fetch of the same song — the server's current claim about that
    ///     file's size.
    ///   - lastValidatedAt: `CachedMediaInfo.lastValidatedAt` — when this
    ///     asset's freshness was last actually confirmed (bumped on every
    ///     `.stillFresh` decision, and implicitly reset to "now" whenever a
    ///     fresh download lands via `.staleNeedsRefetch`).
    ///   - now: Injectable purely for deterministic testing — every real
    ///     call site uses the default.
    ///   - staleAfter: Injectable for the same testing reason; every real
    ///     call site uses `defaultStaleAfter`.
    public static func decide(
        cachedSizeBytes: Int,
        serverSizeBytes: Int,
        lastValidatedAt: Date,
        now: Date = Date(),
        staleAfter: TimeInterval = defaultStaleAfter
    ) -> MediaCacheRevalidationDecision {
        guard isDueForRecheck(lastValidatedAt: lastValidatedAt, now: now, staleAfter: staleAfter) else {
            return .skipTooRecent
        }
        return cachedSizeBytes == serverSizeBytes ? .stillFresh : .staleNeedsRefetch
    }

    /// Whether `staleAfter` has elapsed since `lastValidatedAt` — the shared
    /// "don't even bother checking yet" gate BOTH `decide(...)`'s sizeBytes
    /// comparison and #1460's conditional-GET path use, extracted (#1460) so
    /// the caller can apply it BEFORE deciding which comparison mechanism to
    /// even reach for (a conditional GET is still a real request).
    ///
    /// ELI5: "Has it been long enough since we last checked that we should
    /// even bother looking again?"
    public static func isDueForRecheck(lastValidatedAt: Date, now: Date = Date(), staleAfter: TimeInterval = defaultStaleAfter) -> Bool {
        now.timeIntervalSince(lastValidatedAt) >= staleAfter
    }

    /// Maps a REAL conditional GET's 304/200 answer to the same
    /// three-case decision `decide(...)` returns (#1460) — always either
    /// `.stillFresh` or `.staleNeedsRefetch`, never `.skipTooRecent`: by the
    /// time this is called, a real network request has already happened,
    /// which only makes sense after the caller has ALREADY confirmed
    /// `isDueForRecheck(lastValidatedAt:...)` — this function has no TTL
    /// logic of its own to avoid re-deriving that same gate twice.
    ///
    /// ELI5: "The server just told us straight up whether this file changed
    /// — here's what to do about it."
    public static func decideConditional(outcome: MediaCacheConditionalOutcome) -> MediaCacheRevalidationDecision {
        switch outcome {
        case .notModified:
            .stillFresh
        case .replaced:
            .staleNeedsRefetch
        }
    }
}
