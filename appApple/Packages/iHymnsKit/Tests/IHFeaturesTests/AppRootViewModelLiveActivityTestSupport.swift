// AppRootViewModelLiveActivityTestSupport.swift
// IHFeaturesTests
//
// ELI5: The small test-only helpers `AppRootViewModelLiveActivityTests.swift`
// and `AppRootViewModelLiveActivityNavTests.swift` BOTH need — a spy
// controller that stands in for the real ActivityKit one, a locked mailbox
// for capturing a request off the mock transport, and a few tiny request-
// building/polling helpers.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Split out purely to
// keep BOTH sibling test files under the repo's LOC-budget tripwire
// (`Scripts/loc-budget.sh`, which scans every `*.swift` file under
// `appApple/` including test files) — the SAME "same-concern test code in
// its own file, `internal` not `private` so sibling test files can use it"
// split `ProjectionViewModelTestSupport.swift` already establishes in this
// exact test target (see that file's own header for the identical
// reasoning). Every declaration below is plain `internal` (the default,
// stated for clarity) rather than `private` — Swift's `private` is
// FILE-scoped, so a shared helper needs at least `internal` visibility to
// be usable from a different file in the same test target.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHLiveActivity
@testable import IHModels
@testable import IHPersistence

/// A bare recorder — never touches ActivityKit, just remembers every
/// command `AppRootViewModel` forwarded to it.
@MainActor
final class SpyNowSingingActivityController: NowSingingActivityControlling {
    var onPushTokenHex: (@Sendable (String) async -> Void)?
    private(set) var received: [NowSingingActivityCommand] = []

    func apply(_ command: NowSingingActivityCommand) async {
        received.append(command)
    }
}

/// A small, manually-synchronized mutable box — `AppRootViewModelFavoritesTests
/// .swift`'s own `LockedBox` is `private` (file-scoped), so this shared
/// support file needs its own, DISTINCTLY-NAMED, INTERNAL copy — Swift
/// forbids a module-visible `internal` type sharing a bare name with
/// another file's `private` one, the SAME reason `AppRootViewModelSetlistsTests
/// .swift`'s own copy is named `SetlistsLockedBox` rather than plain
/// `LockedBox`.
final class LiveActivityLockedBox<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var value: Value?

    func set(_ newValue: Value) {
        lock.lock()
        defer { lock.unlock() }
        value = newValue
    }

    var current: Value? {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
}

actor InMemoryTokenStore: TokenStoring {
    private var token: String?
    func save(_ token: String) async throws { self.token = token }
    func load() async throws -> String? { token }
    func delete() async throws { token = nil }
}

@MainActor
func makeViewModel(
    apiClient: APIClient,
    nowSingingActivity: (any NowSingingActivityControlling)?
) throws -> AppRootViewModel {
    let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
    let offlineStore = try OfflineStore(path: nil)
    let liveFollowEngine = LiveFollowEngine(apiClient: apiClient)
    return AppRootViewModel(
        sessionController: sessionController,
        apiClient: apiClient,
        offlineStore: offlineStore,
        liveFollowEngine: liveFollowEngine,
        serviceModeEngine: ServiceModeEngine(apiClient: apiClient),
        nowSingingActivity: nowSingingActivity
    )
}

func response(for request: URLRequest, status: Int = 200) -> HTTPURLResponse {
    // swiftlint:disable:next force_unwrapping
    HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
}

func action(from request: URLRequest) -> String {
    guard let query = request.url?.query else { return "" }
    for pair in query.split(separator: "&") {
        let parts = pair.split(separator: "=", maxSplits: 1)
        if parts.first == "action" { return parts.count > 1 ? String(parts[1]) : "" }
    }
    return ""
}

/// `URLSession` frequently moves a POST's body into `httpBodyStream` rather
/// than leaving it on `httpBody` once the request has actually round-tripped
/// through the loading system (even a mocked one) — the SAME well-known
/// quirk `IHAnalyticsNetworkSinkTests.swift`'s own
/// `analyticsSinkTestsRequestBodyData(_:)` and `AppRootViewModelSetlistsTests
/// .swift`'s header document; this file needs its own copy for the same
/// file-scoped-`private` reason `LiveActivityLockedBox` above does.
func requestBodyData(_ request: URLRequest) -> Data {
    if let body = request.httpBody { return body }
    guard let stream = request.httpBodyStream else { return Data() }
    stream.open()
    defer { stream.close() }
    var data = Data()
    let bufferSize = 4096
    var buffer = [UInt8](repeating: 0, count: bufferSize)
    while stream.hasBytesAvailable {
        let bytesRead = stream.read(&buffer, maxLength: bufferSize)
        guard bytesRead > 0 else { break }
        data.append(buffer, count: bytesRead)
    }
    return data
}

/// Polls `condition` until true or a generous timeout — `syncNowSingingActivity()`
/// forwards to the controller via a fire-and-forget `Task { await
/// nowSingingActivity.apply(command) }` (`AppRootViewModel
/// +LiveActivity.swift`), so a test asserting on `spy.received` the INSTANT
/// a driving call (`goLive()`/`liveSongViewed(_:)`/`hostAdvanceSection(_:)`)
/// returns is racing that Task's own scheduling — same reasoning
/// `LiveFollowEngineLoopTests.swift`'s own `waitUntil` documents for the
/// engine's event stream.
func waitUntil(timeoutMs: Int = 2_000, _ condition: @Sendable () async -> Bool) async {
    let deadline = ContinuousClock.now.advanced(by: .milliseconds(timeoutMs))
    while await !condition(), ContinuousClock.now < deadline {
        try? await Task.sleep(nanoseconds: 5_000_000)
    }
}
