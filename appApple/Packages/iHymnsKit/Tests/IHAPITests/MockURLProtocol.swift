// MockURLProtocol.swift
// IHAPITests
//
// ELI5: A pretend network — instead of `APIClient` actually calling the
// real iHymns server, it hands the request to a little Swift closure THIS
// test file controls, and gets back whatever fake (or real, fixture-backed)
// response that closure decides to hand back.
//
// DETAILED: The standard `URLProtocol`-stubbing technique for testing
// `URLSession`-based code without touching the network (Apple's own
// guidance: https://developer.apple.com/documentation/foundation/urlprotocol).
// `requestHandler` is a `nonisolated(unsafe) static var` rather than
// actor-isolated state — `URLProtocol`'s override points
// (`canInit(with:)`/`startLoading()`/...) are plain, non-async, non-actor
// -isolated class/instance methods mandated by the `URLProtocol` API
// itself, so there's no way to route them through an `actor`. Safety here
// comes from EVERY test file that mutates `requestHandler` marking its
// `@Suite` `.serialized` (see `NetworkedAPIClientTests.swift`) — Swift
// Testing then guarantees at most one test in that suite runs at a time,
// so there is never a genuinely concurrent read/write of this static var,
// only a sequential handoff across a `URLSession` queue hop (which itself
// provides the needed memory barrier).
import Foundation

final class MockURLProtocol: URLProtocol {
    /// What to hand back for the next request this protocol intercepts.
    /// Set it, run one `await client.someRead()` call, then reset it to
    /// `nil` in a `defer` — see any test in `NetworkedAPIClientTests.swift`.
    nonisolated(unsafe) static var requestHandler: (@Sendable (URLRequest) throws -> (HTTPURLResponse, Data))?

    /// Builds a `URLSession` wired to route every request through
    /// `requestHandler` instead of a real socket.
    static func makeSession() -> URLSession {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [MockURLProtocol.self]
        return URLSession(configuration: configuration)
    }

    override static func canInit(with request: URLRequest) -> Bool { true }
    override static func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        guard let handler = MockURLProtocol.requestHandler else {
            client?.urlProtocol(self, didFailWithError: URLError(.unknown))
            return
        }
        do {
            let (response, data) = try handler(request)
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {
        // No cleanup needed — `requestHandler` is reset by the calling
        // test's own `defer`, not by this protocol instance.
    }
}

/// A small, manually-synchronized mutable counter — used by the retry
/// tests below to count how many times the mock transport was hit, without
/// capturing a bare `var` in the `@Sendable requestHandler` closure (which
/// Swift 6 strict concurrency correctly refuses to compile).
final class LockedCounter: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0

    @discardableResult
    func increment() -> Int {
        lock.lock()
        defer { lock.unlock() }
        value += 1
        return value
    }

    var current: Int {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
}
