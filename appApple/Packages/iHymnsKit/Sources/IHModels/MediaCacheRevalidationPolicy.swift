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
        guard now.timeIntervalSince(lastValidatedAt) >= staleAfter else {
            return .skipTooRecent
        }
        return cachedSizeBytes == serverSizeBytes ? .stillFresh : .staleNeedsRefetch
    }
}
