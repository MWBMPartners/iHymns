// TVServiceFollowCoordinatorTests.swift
// IHFeaturesTests
//
// ELI5: Checks that a blackout/logo change riding along with a song the TV
// can't recognise still actually blacks out (or shows the logo on) the
// projection — the projector shouldn't stay stuck showing an old song's
// lyrics just because THAT song's id happens to be one of the ~10 legacy
// ones that don't parse.
//
// DETAILED: Apple Phase-2 correctness sweep (#1428, F-1). Drives
// `TVServiceFollowCoordinator.applySnapshotAndUpdateNotice(_:)` directly
// (`internal`, not `private` — see that method's own doc comment for why)
// with a fabricated `LiveBroadcastSnapshot`, rather than standing up a real
// `ServiceModeEngine` event loop + network mock just to reach it — the
// SAME "construct the real object, call the testable seam directly" shape
// `LiveSyncUIStateTests.swift`'s `viewModel.apply(_:)` calls already
// establish for the sibling `AppRootViewModel` event-application path. The
// `ServiceModeEngine` here is never actually driven (no `join`/`resumeIfPossible`
// call) — it exists purely because `TVServiceFollowCoordinator.init` requires
// one; no network call is ever made.
import Foundation
import Testing
import IHAPI
import IHLive
import IHModels
@testable import IHFeatures

@Suite("TVServiceFollowCoordinator.applySnapshotAndUpdateNotice (#1428 F-1)")
@MainActor
struct TVServiceFollowCoordinatorTests {

    private func makeCoordinator() -> (TVServiceFollowCoordinator, ProjectionViewModel) {
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.offline })
        let engine = ServiceModeEngine(apiClient: APIClient(environment: .dev))
        return (TVServiceFollowCoordinator(projectionViewModel: viewModel, engine: engine), viewModel)
    }

    @Test("a snapshot with an unparsable songId + a blackout displayState still blacks out the projection")
    func unparsableSongIdStillAppliesDisplayState() async {
        let (coordinator, viewModel) = makeCoordinator()
        let snapshot = LiveBroadcastSnapshot(
            songId: "LEGACY_9", componentIndex: nil, state: LiveBroadcastState(displayStateRaw: "blackout"), revision: 1
        )

        await coordinator.applySnapshotAndUpdateNotice(snapshot)

        #expect(viewModel.displayState == .blackout)
        #expect(coordinator.songNotice == "This song can't be displayed on this TV.")
    }

    @Test("an unparsable songId with no displayState change leaves the notice set and no crash")
    func unparsableSongIdWithoutDisplayStateChangeStillSetsNotice() async {
        let (coordinator, viewModel) = makeCoordinator()
        // No `state` at all -> `TVServiceFollowBridge.displayState(from:)`
        // resolves to `.lyrics`, which already matches the projector's
        // fresh `.lyrics` default, so `plan.displayState` is nil here — the
        // regression this test guards is that the EARLY RETURN for the
        // unparsable id must never depend on `plan.displayState` being
        // non-nil to be reached safely.
        let snapshot = LiveBroadcastSnapshot(songId: "LEGACY_9", componentIndex: nil, state: nil, revision: 2)

        await coordinator.applySnapshotAndUpdateNotice(snapshot)

        #expect(viewModel.displayState == .lyrics)
        #expect(coordinator.songNotice == "This song can't be displayed on this TV.")
    }
}
