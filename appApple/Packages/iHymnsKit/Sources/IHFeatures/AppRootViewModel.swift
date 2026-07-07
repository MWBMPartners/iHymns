// AppRootViewModel.swift
// IHFeatures
//
// ELI5: The "front desk" object every app shell (iPhone/iPad/Mac, tvOS,
// watchOS) creates once at launch — it holds the app's four engines
// (network, auth, offline cache, live-session) and mirrors "are we signed
// in?" onto the main thread so SwiftUI views can read it directly.
//
// DETAILED: This is IHFeatures' composition root — the concrete proof that
// the `IHFeatures (→ all)` dependency direction from strategy §1.2 actually
// wires together and compiles: it holds one instance each of
// `IHAuth.SessionController`, `IHAPI.APIClient`, `IHPersistence.OfflineStore`,
// and `IHLive.LiveFollowEngine`. Per strategy §1.3 ("`@Observable` MainActor
// VMs, no Combine"), it's `@MainActor` + `@Observable` (the Observation
// framework, not Combine) and bridges `SessionController`'s actor-isolated
// `AsyncStream<SessionState>` onto a plain `@MainActor` stored property —
// this bridging pattern (actor state → AsyncStream → MainActor mirror) is
// exactly what every Phase-1 feature view model built on top of the real
// engines will repeat, so it's worth getting right here at Phase 0 even
// though the screens that will actually consume it are still placeholders.
//
// Dependencies are all INJECTED (never constructed internally) — the app
// shells are responsible for building the real `APIEnvironment`/
// `KeychainTokenStore`/on-disk `OfflineStore` path per strategy §1.6's
// custody rules; a shared library type constructing its own Keychain
// access-group or a hard-coded file path would bake shell-specific
// decisions into code that has no business making them.
import Foundation
import IHAPI
import IHAuth
import IHLive
import IHModels
import IHPersistence
import Observation

/// The root view model every app shell instantiates once at launch and
/// injects into its top-level scene.
///
/// ELI5: Holds the four "engines" and keeps a main-thread-safe copy of
/// "are we signed in?" for views to read.
@MainActor
@Observable
public final class AppRootViewModel {
    /// A main-thread-readable mirror of `SessionController.state`,
    /// kept in sync via `stateUpdates` (see `observeSessionState()` below).
    ///
    /// ELI5: "Are we signed in?" — safe for any SwiftUI view to read
    /// directly, no `await` needed.
    public private(set) var sessionState: SessionState = .signedOut

    private let sessionController: SessionController
    private let apiClient: APIClient
    private let offlineStore: OfflineStore
    private let liveFollowEngine: LiveFollowEngine

    /// The background observation loop mirroring `sessionController.stateUpdates`
    /// into `sessionState`. Held so it can be cancelled in `deinit`.
    ///
    /// DETAILED: `deinit` on a `@MainActor` class is itself `nonisolated`
    /// (deallocation can be triggered from any thread, so the compiler
    /// can't prove actor isolation there — Swift 6 strict concurrency
    /// forbids touching MainActor-isolated state from it). By the time
    /// `deinit` runs, though, there are — by definition — zero other
    /// references to `self`, so no concurrent access to this property can
    /// possibly be racing with the cancel below; `nonisolated(unsafe)` is
    /// the sanctioned escape hatch for exactly this "provably safe, but the
    /// compiler can't see why" situation (see the Swift evolution pitch on
    /// task cancellation in `deinit`, and Migrating to Swift 6:
    /// https://www.swift.org/migration/documentation/migrationguide/).
    ///
    /// `@ObservationIgnored` because this is pure plumbing, not UI-facing
    /// state (a SwiftUI view should never re-render because this changed);
    /// it also sidesteps `@Observable`'s macro-generated tracked accessor,
    /// which does not support plain `nonisolated` on a mutable stored
    /// property — `nonisolated(unsafe)` is the correct tool once the
    /// property is no longer being wrapped by that macro. Safety argument
    /// is unchanged: by the time `deinit` reads/cancels it, `self` has no
    /// other live references, so nothing can race with this access.
    @ObservationIgnored
    nonisolated(unsafe) private var sessionObservationTask: Task<Void, Never>?

    public init(
        sessionController: SessionController,
        apiClient: APIClient,
        offlineStore: OfflineStore,
        liveFollowEngine: LiveFollowEngine
    ) {
        self.sessionController = sessionController
        self.apiClient = apiClient
        self.offlineStore = offlineStore
        self.liveFollowEngine = liveFollowEngine
        observeSessionState()
    }

    deinit {
        sessionObservationTask?.cancel()
    }

    /// The list of songs currently cached for offline use — a thin,
    /// MainActor-safe read-through to `OfflineStore`, demonstrating
    /// IHFeatures' IHPersistence dependency without pulling GRDB types into
    /// this module's public surface.
    ///
    /// ELI5: "What songs have we already saved for offline use?"
    public func cachedSongSummaries() async throws -> [SongSummary] {
        try await offlineStore.allSongSummaries()
    }

    /// Starts a `Task` that mirrors every `SessionState` change published by
    /// `sessionController` onto `sessionState`.
    ///
    /// ELI5: Keeps our main-thread copy of "signed in or not" always up to
    /// date.
    ///
    /// DETAILED: Created as a plain (non-detached) `Task` from within this
    /// `@MainActor` initializer, so — per Swift's structured-concurrency
    /// isolation-inheritance rule — the task's body itself runs
    /// MainActor-isolated, meaning `self.sessionState = state` below needs
    /// no extra `await MainActor.run { ... }` hop. Captures `self` WEAKLY:
    /// `sessionController.stateUpdates` never terminates on its own, so a
    /// strong capture here would keep this task (and therefore this whole
    /// view model) alive forever — a classic retain cycle. With a weak
    /// capture, once `self` is deallocated the loop simply exits on its
    /// next iteration instead of pinning it in memory.
    private func observeSessionState() {
        sessionObservationTask = Task { [weak self, sessionController] in
            for await state in sessionController.stateUpdates {
                guard let self, !Task.isCancelled else { break }
                self.sessionState = state
            }
        }
    }
}
