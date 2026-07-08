// AppRootViewModel+Live.swift
// IHFeatures
//
// ELI5: The one function that builds the REAL app — wires up the real
// Keychain, the real network client, and (for now) an in-memory offline
// cache, all pointed at one server environment.
//
// DETAILED: #1399's original composition-root factory, split out of
// `AppRootViewModel.swift` itself (native login/account UI + favourites
// task) purely to keep that file from re-growing past the repo's
// LOC-budget tripwire (`Scripts/loc-budget.sh`) now that it also carries
// `favorites`/`currentUser` state — the exact same "a Swift extension can't
// add new STORED properties, so behaviour-only code is free to live in its
// own file" reasoning `AppRootViewModel+Search.swift`'s own header already
// documents. `makeLive(environment:)` itself is UNCHANGED from its original
// `AppRootViewModel.swift` home — this is a pure file move, not a
// behavioural edit.
import Foundation
import IHAPI
import IHAuth
import IHLive
import IHPersistence

extension AppRootViewModel {
    /// Builds the "real" four-engine composition every live app shell
    /// should use — Keychain-backed auth, the given API environment, and a
    /// REAL on-disk offline cache — centralised here so this exact wiring is
    /// defined ONCE rather than repeated (and inevitably drifting) across
    /// `IHymnsApp.swift`/a future `IHymnsTVApp.swift`/`IHymnsWatchApp.swift`
    /// real-screen wiring.
    ///
    /// ELI5: "Build me the real app, talking to this environment."
    ///
    /// DETAILED: #1399's original Phase-0 version of this factory opened an
    /// IN-MEMORY `OfflineStore(path: nil)` — an honest placeholder at the
    /// time, since nothing yet WROTE anything worth persisting (the
    /// `song_summary` cache table existed but no call site ever upserted
    /// into it). #181's favourites feature is the first genuinely
    /// persist-worthy write this package makes, and its own brief is
    /// explicit that a favourite made offline must "survive relaunch" — an
    /// in-memory database, by definition, cannot do that (every row
    /// vanishes the moment the process exits). `offlineStorePath()` below
    /// switches this factory to a REAL on-disk SQLite file, which also
    /// happens to make the pre-existing `song_summary` cache durable across
    /// relaunches for the first time too — a welcome side effect, not scope
    /// creep: it was always going to need a real path eventually (strategy
    /// §3.4's "#187 offline" Phase-1 work), and #181 is what actually needs
    /// it now. Every EXISTING test in this package still opens its own
    /// `OfflineStore(path: nil)` directly (never through this factory), so
    /// this change has zero effect on `swift test`.
    @MainActor
    public static func makeLive(environment: APIEnvironment) -> AppRootViewModel {
        let apiClient = APIClient(environment: environment)
        let tokenStore = KeychainTokenStore()
        let sessionController = SessionController(tokenStore: tokenStore, apiClient: apiClient)
        // swiftlint:disable:next force_try
        let offlineStore = try! OfflineStore(path: offlineStorePath(), mediaCacheDirectory: mediaCacheDirectory())
        let liveFollowEngine = LiveFollowEngine(apiClient: apiClient)

        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: offlineStore,
            liveFollowEngine: liveFollowEngine
        )
    }

    /// The on-disk path for the live app's offline database — inside the
    /// platform's standard Application Support directory (identical API on
    /// every Apple platform this package targets, per Apple's
    /// `FileManager.SearchPathDirectory` docs:
    /// https://developer.apple.com/documentation/foundation/filemanager/searchpathdirectory/applicationsupportdirectory),
    /// in an `iHymns` subfolder (created if missing) so this database file
    /// never collides with another app's data on macOS's shared-per-user
    /// Application Support root.
    ///
    /// ELI5: "Where on this device should we keep the offline notebook
    /// file, so it's still there next time the app opens?"
    ///
    /// DETAILED: Falls back to `temporaryDirectory` only in the practically
    /// -unreachable case `FileManager` can't resolve Application Support at
    /// all — still a valid writable path (so `OfflineStore.init` never
    /// force-unwraps into a crash), just one the OS may purge sooner; this
    /// mirrors the defensive-but-never-blocking posture the rest of this
    /// package takes toward storage failures (e.g. `CachedSongSummary
    /// .toSongSummary()`'s "one bad row never takes down the cache").
    private static func offlineStorePath() -> String {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
            ?? FileManager.default.temporaryDirectory
        let directory = base.appendingPathComponent("iHymns", isDirectory: true)
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory.appendingPathComponent("offline.sqlite").path
    }

    /// The on-disk directory for the live app's cached media FILES (#1440)
    /// — a `MediaCache` subfolder alongside `offlineStorePath()`'s own
    /// `offline.sqlite`, inside the SAME `iHymns` Application Support
    /// folder (never a second top-level location) so both halves of this
    /// device's offline data live under one predictable root. `OfflineStore
    /// .init(path:mediaCacheDirectory:)` itself creates this directory if
    /// it doesn't already exist, so this function only needs to compute the
    /// path — see that initializer's own doc comment.
    ///
    /// ELI5: "Where on this device should we keep downloaded audio/PDF/MIDI/
    /// MusicXML files?"
    private static func mediaCacheDirectory() -> URL {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
            ?? FileManager.default.temporaryDirectory
        return base.appendingPathComponent("iHymns", isDirectory: true).appendingPathComponent("MediaCache", isDirectory: true)
    }
}
