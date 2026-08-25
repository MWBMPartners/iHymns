// CaptchaChallengeTests.swift
// IHFeaturesTests
//
// ELI5: Proves the CAPTCHA "socket" (`CaptchaChallengeRegistry`) remembers
// whatever provider is plugged into it and hands the SAME one back by name
// — and proves the whole scaffold stays completely INERT (no behaviour
// change at all) on a dormant install, which is the one thing #947/#340's
// scaffold must never get wrong.
//
// DETAILED: `.claude/captcha-native-and-outage-plan.md` §2.4/§2.8.
// `CaptchaChallengeRegistryTests` uses a tiny fake conformance (never the
// real `TurnstileCaptchaProvider`, which needs `WebKit` — irrelevant to
// what THIS suite is proving: the registry's own string-keyed lookup, not
// any one provider's rendering). `AppRootViewModelCaptchaTests` mirrors
// `AppRootViewModelFavoritesTests.swift`'s exact harness shape
// (`makeViewModel()`, `MockTransportLock.shared.withLock`, action-routed
// `MockURLProtocol.requestHandler`) — the SAME "real production types,
// faked transport only" posture every other IHFeatures integration test in
// this package already uses.
import Foundation
import SwiftUI
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

/// A minimal, non-`WebKit` fake conformance — proves the REGISTRY's own
/// contract (register under `providerKey`, resolve by that same key) with
/// no dependency on any real provider's rendering internals.
@MainActor
private final class FakeCaptchaProvider: CaptchaChallengeProviding {
    let providerKey: String
    private(set) var resetCallCount = 0

    init(providerKey: String = "fake-provider") {
        self.providerKey = providerKey
    }

    var isSupportedOnThisPlatform: Bool { true }

    func makeChallengeView(config: CaptchaConfig, onToken: @escaping (String) -> Void) -> AnyView {
        AnyView(EmptyView())
    }

    func reset() {
        resetCallCount += 1
    }
}

// `.serialized` — every test below mutates the SAME process-wide static
// `CaptchaChallengeRegistry.providers` dictionary (via `resetForTesting()`/
// `register(_:)`); without it, Swift Testing's default concurrent execution
// of tests WITHIN one suite could interleave two tests' resets/registers
// unpredictably (the identical "shared static state" hazard
// `MockTransportLock.swift`'s own header documents for `MockURLProtocol
// .requestHandler`, just within-suite here rather than cross-suite).
@Suite("CaptchaChallengeRegistry", .serialized)
@MainActor
struct CaptchaChallengeRegistryTests {

    @Test("register(_:) then provider(for:) resolves the SAME instance back by its providerKey")
    func registersAndResolves() {
        CaptchaChallengeRegistry.resetForTesting()
        let fake = FakeCaptchaProvider(providerKey: "fake-provider")
        CaptchaChallengeRegistry.register(fake)

        let resolved = CaptchaChallengeRegistry.provider(for: "fake-provider")
        #expect(resolved === fake)
    }

    @Test("provider(for:) returns nil for a key nothing has registered — an unresolvable provider degrades to nil, never a crash")
    func unregisteredKeyResolvesToNil() {
        CaptchaChallengeRegistry.resetForTesting()
        #expect(CaptchaChallengeRegistry.provider(for: "some-future-provider-nobody-shipped") == nil)
    }

    @Test("register(_:) called twice for the SAME key replaces the earlier registration (idempotent)")
    func reregisteringReplaces() {
        CaptchaChallengeRegistry.resetForTesting()
        let first = FakeCaptchaProvider(providerKey: "fake-provider")
        let second = FakeCaptchaProvider(providerKey: "fake-provider")
        CaptchaChallengeRegistry.register(first)
        CaptchaChallengeRegistry.register(second)

        let resolved = CaptchaChallengeRegistry.provider(for: "fake-provider")
        #expect(resolved === second)
        #expect(resolved !== first)
    }

    @Test("TurnstileCaptchaProvider reports the registry key \"turnstile\" — the owner-decided provider (plan OWNER DECISIONS)")
    func turnstileProviderKey() {
        #expect(TurnstileCaptchaProvider().providerKey == "turnstile")
    }

    @Test("A registered provider's reset() is reachable through the registry — the mechanism LoginView's resetCaptcha() relies on")
    func resetReachesTheRegisteredInstance() {
        CaptchaChallengeRegistry.resetForTesting()
        let fake = FakeCaptchaProvider(providerKey: "fake-provider")
        CaptchaChallengeRegistry.register(fake)

        CaptchaChallengeRegistry.provider(for: "fake-provider")?.reset()
        CaptchaChallengeRegistry.provider(for: "fake-provider")?.reset()

        #expect(fake.resetCallCount == 2)
    }
}

@Suite("AppRootViewModel — CAPTCHA scaffold dormancy (#947/#340)", .serialized)
struct AppRootViewModelCaptchaTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private static func action(of request: URLRequest) -> String? {
        guard let query = request.url?.query else { return nil }
        for item in query.split(separator: "&") where item.hasPrefix("action=") {
            return String(item.dropFirst("action=".count))
        }
        return nil
    }

    // ELI5: this helper has to be marked "runs on the main screen thread",
    // because the thing it builds insists on being built there.
    //
    // DETAILED: `@MainActor` is REQUIRED, not decorative. `AppRootViewModel`
    // is a `@MainActor @Observable` class (`AppRootViewModel.swift`), so its
    // `init` is main-actor-isolated, and Swift 6 forbids calling it from a
    // synchronous NON-isolated context — which is what this helper is without
    // the attribute, since `AppRootViewModelCaptchaTests` is a plain
    // (un-isolated) `@Suite` struct. Left off, this is:
    // "error: call to main actor-isolated initializer ... in a synchronous
    // nonisolated context". Every OTHER `makeViewModel()` in this test target
    // (`AppRootViewModelFavoritesTests`/`…SetlistsTests`/`…AccountDeleteTests`)
    // carries the same attribute for the same reason, and every call site
    // correspondingly reads `try await makeViewModel()` — the `await` is what
    // hops onto the main actor from `withLock`'s non-isolated closure.
    // https://developer.apple.com/documentation/swift/mainactor
    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    @Test("On a DORMANT install (app_status with no captcha key), captchaConfig stays nil and captchaRequired(for:) is false for every form — the scaffold is INERT")
    func dormantInstallStaysInert() async throws {
        try await MockTransportLock.shared.withLock {
            let viewModel = try await makeViewModel()
            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "app_status":
                    return (Self.response(status: 200), Data(#"{"maintenance":false,"motd":""}"#.utf8))
                default:
                    return (Self.response(status: 200), Data("{}".utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            await viewModel.restoreSessionIfNeeded()

            #expect(await viewModel.captchaConfig == nil)
            #expect(await viewModel.captchaRequired(for: CaptchaConfig.loginFormKey) == false)
            #expect(await viewModel.captchaRequired(for: CaptchaConfig.emailLoginFormKey) == false)
            // The un-configured install's sign-in restore must proceed
            // completely unaffected — this is the "app's behaviour is
            // unchanged with no provider configured" proof the scaffold
            // must never break.
            #expect(await viewModel.sessionState == .signedOut)
        }
    }

    @Test("An OFFLINE/failing app_status call (transient failure) still leaves captchaConfig nil and never blocks sign-in restore")
    func failedAppStatusCallDegradesSafely() async throws {
        try await MockTransportLock.shared.withLock {
            let viewModel = try await makeViewModel()
            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "app_status":
                    return (Self.response(status: 503), Data())
                default:
                    return (Self.response(status: 200), Data("{}".utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            // Must not throw — `loadAppStatus()`'s own `try?` swallows the
            // failure (see that method's doc comment).
            await viewModel.restoreSessionIfNeeded()

            #expect(await viewModel.captchaConfig == nil)
        }
    }

    @Test("On a CONFIGURED install, restoreSessionIfNeeded() populates captchaConfig from the real app_status shape")
    func configuredInstallPopulatesConfig() async throws {
        try await MockTransportLock.shared.withLock {
            let viewModel = try await makeViewModel()
            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "app_status":
                    let body = Data("""
                    { "captcha": { "provider": "turnstile", "siteKey": "k", "scriptUrl": "https://challenges.cloudflare.com/turnstile/v0/api.js", "renderGlobal": "turnstile", "field": "cf-turnstile-response", "forms": ["login", "email_login"] } }
                    """.utf8)
                    return (Self.response(status: 200), body)
                default:
                    return (Self.response(status: 200), Data("{}".utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            await viewModel.restoreSessionIfNeeded()

            #expect(await viewModel.captchaConfig?.provider == "turnstile")
            #expect(await viewModel.captchaRequired(for: CaptchaConfig.loginFormKey) == true)
            #expect(await viewModel.captchaRequired(for: CaptchaConfig.emailLoginFormKey) == true)
            #expect(await viewModel.captchaRequired(for: "registration") == false)

            // Deliberately NOT also asserting on `CaptchaChallengeRegistry
            // .provider(for: "turnstile")` here: that registry is
            // PROCESS-WIDE mutable state (`CaptchaChallengeRegistryTests`,
            // this same test target, calls `resetForTesting()`), and Swift
            // Testing runs different suites concurrently by default — the
            // exact cross-suite race `MockTransportLock`'s own header
            // documents for `MockURLProtocol.requestHandler`. Rather than
            // repurpose that UNRELATED lock for a second shared resource,
            // this test simply doesn't depend on the registry's state
            // surviving a concurrently-running suite; `loadAppStatus()`
            // actually calling `CaptchaChallengeRegistry.register(_:)` is
            // covered directly by reading that method's source, and the
            // registry's OWN round-trip contract is proven in isolation by
            // `CaptchaChallengeRegistryTests` above.
        }
    }
}

/// A test-only in-memory `TokenStoring` — mirrors every OTHER
/// `AppRootViewModel*Tests.swift` file's own private copy in this same test
/// target (`private` is file-scoped in Swift, so each integration-test file
/// declares its own rather than sharing one across files).
private actor InMemoryTokenStore: TokenStoring {
    private var token: String?

    func save(_ token: String) async throws {
        self.token = token
    }

    func load() async throws -> String? {
        token
    }

    func delete() async throws {
        token = nil
    }
}
