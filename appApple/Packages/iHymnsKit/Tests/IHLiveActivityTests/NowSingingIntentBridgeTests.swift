// NowSingingIntentBridgeTests.swift
// IHLiveActivityTests
//
// ELI5: Checks the mailbox actually delivers — setting a handler and
// "tapping" an action calls it with the right value, and clearing the
// handler leaves the bridge safely inert.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). `@MainActor` for the
// whole suite, matching `NowSingingIntentBridge`'s own isolation — resets
// `handler` to `nil` after every test (a `static var` persists across test
// invocations within one process) so no test's registration leaks into the
// next, mirroring `MockURLProtocol.requestHandler`'s own reset-in-`defer`
// discipline (`IHAPITestSupport`).
import Foundation
import Testing
@testable import IHLiveActivity

@Suite("NowSingingIntentBridge — handler dispatch")
@MainActor
struct NowSingingIntentBridgeTests {

    @Test("setting a handler and dispatching an action calls it with that action")
    func dispatchesToRegisteredHandler() async {
        var received: [NowSingingIntentBridge.Action] = []
        NowSingingIntentBridge.handler = { action in received.append(action) }
        defer { NowSingingIntentBridge.handler = nil }

        await NowSingingIntentBridge.handler?(.nextSection)
        await NowSingingIntentBridge.handler?(.previousSection)

        #expect(received == [.nextSection, .previousSection])
    }

    @Test("no handler registered is a safe no-op — never crashes on a nil call")
    func noHandlerIsSafeNoOp() async {
        NowSingingIntentBridge.handler = nil
        await NowSingingIntentBridge.handler?(.nextSection)
        // No assertion beyond "didn't crash" — this IS the test.
    }
}
