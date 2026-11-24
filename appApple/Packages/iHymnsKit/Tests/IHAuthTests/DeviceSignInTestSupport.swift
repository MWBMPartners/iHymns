// DeviceSignInTestSupport.swift
// IHAuthTests
//
// ELI5: The shared "workbench" for the device sign-in tests: pretend safes,
// a pretend shared-sign-in window, a request recorder, and canned server
// answers, so each test can say in a few lines "the device has THIS in its
// safes and the server answers THAT" and then check what the controller did.
//
// DETAILED: PR 3b step I2c (#2180). Everything uses `InMemoryTokenStore` (the
// real Keychain cannot be exercised by `swift test` on a CI runner) and
// `MockURLProtocol` (the real network is never touched). The two test files
// that use this (`SessionControllerDeviceSignInTests` for the launch paths,
// `SessionControllerDeviceSignOutTests` for signing in and out) are split only
// to stay under the repo's 400-line budget (`Scripts/loc-budget.sh`).
import Foundation
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

/// A read-only stand-in for the older apps' shared sign-in. Note it has no way to
/// be written or deleted, like the real reader.
struct FakeLegacyReader: LegacyTokenReading {
    let token: String?
    func load() async throws -> String? { token }
}

/// A fixed install id.
struct FakeIdentity: DeviceIdentityProviding {
    func installId() async throws -> String { "install-1234-abcd" }
}

/// Records every request the controller makes: which action, and whether it
/// carried an Authorization header (and which).
final class RequestLog: @unchecked Sendable {
    struct Entry: Sendable { let action: String; let authorization: String? }
    private let lock = NSLock()
    private var entries: [Entry] = []

    func record(_ request: URLRequest) {
        let query = request.url?.query ?? ""
        let action = query.split(separator: "&").first { $0.hasPrefix("action=") }.map { String($0.dropFirst("action=".count)) } ?? ""
        lock.lock(); defer { lock.unlock() }
        entries.append(Entry(action: action, authorization: request.value(forHTTPHeaderField: "Authorization")))
    }

    var all: [Entry] { lock.lock(); defer { lock.unlock() }; return entries }
    func count(of action: String) -> Int { all.filter { $0.action == action }.count }
    func last(of action: String) -> Entry? { all.last { $0.action == action } }
    var actions: [String] { all.map(\.action) }
    func reset() { lock.lock(); defer { lock.unlock() }; entries = [] }
}

/// Everything one test needs.
struct DeviceHarness {
    let controller: SessionController
    let deviceStore: InMemoryTokenStore
    let keyStore: InMemoryTokenStore
    let notebook: DeviceSignInState
    let log: RequestLog
}

enum DeviceTestData {
    static let legacyToken = String(repeating: "1", count: 64)
    static let ownToken = String(repeating: "2", count: 64)
    static let newToken = String(repeating: "3", count: 64)
    static let key = "ihek1_" + String(repeating: "a", count: 64)
    static let newKey = "ihek1_" + String(repeating: "b", count: 64)

    static func response(_ status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    static let user = #"{"id":7,"username":"jane","display_name":"Jane","role":"user"}"#

    static var meOK: Data { Data(#"{"user":\#(user)}"#.utf8) }

    /// A successful enrol or redeem answer, with a key when `withKey`.
    static func signInOK(token: String = newToken, withKey: Bool = false) -> Data {
        let keyPart = withKey ? #","enrolKey":"\#(newKey)","enrolKeyExpiresAt":"2027-05-23 09:00:00""# : ""
        return Data(#"{"token":"\#(token)","user":\#(user),"device":{"id":"abc"},"expiresAt":"2026-12-24 09:00:00"\#(keyPart)}"#.utf8)
    }

    /// A v2-envelope refusal with a machine reason word.
    static func refusal(_ status: Int, _ reason: String) -> Data {
        Data(#"{"ok":false,"error":{"code":"http_\#(status)","message":"A sentence.","reason":"\#(reason)"}}"#.utf8)
    }

    static func makeHarness(
        legacy: String? = nil,
        key: String? = nil,
        ownToken: String? = nil,
        supportsKeys: Bool = true,
        plain: Bool = false
    ) async throws -> DeviceHarness {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let deviceStore = InMemoryTokenStore()
        let keyStore = InMemoryTokenStore()
        let notebook = DeviceSignInState(store: InMemoryTokenStore())
        if let ownToken { try await deviceStore.save(ownToken) }
        if let key { try await keyStore.save(key) }
        let support: DeviceSignInSupport? = plain ? nil : DeviceSignInSupport(
            enrolKeyStore: supportsKeys ? keyStore : nil,
            legacyReader: FakeLegacyReader(token: legacy),
            deviceIdentity: FakeIdentity(),
            signInState: notebook
        )
        let controller = SessionController(tokenStore: deviceStore, apiClient: apiClient, deviceSignIn: support)
        return DeviceHarness(controller: controller, deviceStore: deviceStore, keyStore: keyStore, notebook: notebook, log: RequestLog())
    }

    /// Installs a handler that records each request, then answers by action.
    static func route(_ log: RequestLog, _ answers: @escaping @Sendable (String) -> (Int, Data)) {
        MockURLProtocol.requestHandler = { request in
            log.record(request)
            let query = request.url?.query ?? ""
            let action = query.split(separator: "&").first { $0.hasPrefix("action=") }.map { String($0.dropFirst("action=".count)) } ?? ""
            let (status, body) = answers(action)
            return (response(status), body)
        }
    }
}
