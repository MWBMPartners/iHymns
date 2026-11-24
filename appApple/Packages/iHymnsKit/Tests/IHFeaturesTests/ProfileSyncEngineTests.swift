// ProfileSyncEngineTests.swift
// IHFeaturesTests
//
// ELI5: Checks the shared-settings sync end to end against a pretend server:
// what it applies from the account, what it sends, and the rules that keep either
// side from quietly overwriting the other's newer choice.
//
// DETAILED: PR 3b step I4 (#2180). `ProfileSyncEngine` takes its two network
// calls as closures, so these tests run it against `FakeProfileServer` (an
// in-memory stand-in) and a throwaway `UserDefaults`, with the REAL bundled
// schema. Runs in CI on macOS, not in the sandbox this was written in. The
// lists the engine's bindings must agree with (registry ids, `UserDefaults`
// keys, allowed values) are checked from the source by
// `tests/test-profile-registry-lockstep.js`.
import Foundation
import Testing
import IHAPI
import IHModels
@testable import IHFeatures

/// A pretend `profile_sync`: replies to reads from a queue and to sends through a handler.
final class FakeProfileServer: @unchecked Sendable {
    private let lock = NSLock()
    private var readQueue: [ProfileSyncReply] = []
    private var sendHandler: @Sendable (ProfileSyncRequest) throws -> ProfileSyncReply = { ProfileSyncReply(cursor: $0.cursor) }
    private var readLog: [Int] = []
    private var sendLog: [ProfileSyncRequest] = []

    func queueRead(_ reply: ProfileSyncReply) { lock.lock(); readQueue.append(reply); lock.unlock() }
    func onSend(_ handler: @escaping @Sendable (ProfileSyncRequest) throws -> ProfileSyncReply) { lock.lock(); sendHandler = handler; lock.unlock() }
    var reads: [Int] { lock.lock(); defer { lock.unlock() }; return readLog }
    var sends: [ProfileSyncRequest] { lock.lock(); defer { lock.unlock() }; return sendLog }

    var transport: ProfileSyncTransport {
        ProfileSyncTransport(
            read: { [self] cursor in
                // `withLock` is the version of locking Swift allows inside async code.
                lock.withLock {
                    readLog.append(cursor)
                    return readQueue.isEmpty ? ProfileSyncReply(cursor: cursor) : readQueue.removeFirst()
                }
            },
            send: { [self] request in
                let handler = lock.withLock { () -> @Sendable (ProfileSyncRequest) throws -> ProfileSyncReply in
                    sendLog.append(request)
                    return sendHandler
                }
                return try handler(request)
            }
        )
    }
}

@Suite("ProfileSyncEngine (I4)", .serialized)
struct ProfileSyncEngineTests {

    private struct Harness {
        let engine: ProfileSyncEngine
        let defaults: UserDefaults
        let local: ProfileLocalSettings
        let store: ProfileSyncStateStore
        let server = FakeProfileServer()
    }

    private func makeHarness() throws -> Harness {
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: "ih-profile-sync-tests-\(UUID().uuidString)")!
        let local = ProfileLocalSettings(defaults: defaults)
        let store = ProfileSyncStateStore(defaults: defaults)
        let engine = ProfileSyncEngine(schema: try ProfileSchema.bundled(), local: local, stateStore: store, appVersion: "1.0.0")
        return Harness(engine: engine, defaults: defaults, local: local, store: store)
    }

    private func change(_ id: String, _ value: ProfileValue?, version: Int, deleted: Bool = false) -> ProfileServerChange {
        let parts = id.split(separator: ".", maxSplits: 1).map(String.init)
        return ProfileServerChange(section: parts[0], key: parts[1], value: value, deleted: deleted, version: version)
    }

    @Test("First sync: reads from 0 whatever the cursor, applies the account's values, and sends nothing it just received")
    func firstSyncAppliesAccountAndSendsNothingBack() async throws {
        let harness = try makeHarness()
        harness.server.queueRead(ProfileSyncReply(cursor: 5, changes: [
            change("appearance.theme", .string("dark"), version: 4),
            change("reading.textScale", .double(1.5), version: 5)
        ]))

        let outcome = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        #expect(outcome == .done(changedLocal: true))
        #expect(harness.server.reads == [0])
        #expect(harness.defaults.string(forKey: "ihAppearanceTheme") == "dark")
        #expect(harness.defaults.double(forKey: "ihLyricsTextScale") == 1.5)
        #expect(harness.server.sends.isEmpty)               // what came FROM the account is never sent back
        #expect(harness.store.load(userId: 1).claimed)
        #expect(harness.store.load(userId: 1).cursor == 5)
    }

    @Test("First sync claims a device value the account never heard of, but only where the entry allows claiming")
    func claimOnlyWhereAllowed() async throws {
        let harness = try makeHarness()
        harness.defaults.set("deuteranopia", forKey: "ihCvdMode")      // appearance.cvd: claim true
        harness.defaults.set(2.0, forKey: "ihLyricsTextScale")         // reading.textScale: claim false

        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        let sent = harness.server.sends.flatMap(\.changes)
        #expect(sent.map { $0.section + "." + $0.key } == ["appearance.cvd"])
        #expect(sent.first?.value == .string("deuteranopia"))
        #expect(sent.first?.operation == .set)
    }

    @Test("A later change on the device is sent once, acknowledged, and not sent again")
    func localChangeIsSentOnce() async throws {
        let harness = try makeHarness()
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)     // first sync: nothing to send
        harness.defaults.set(true, forKey: "ihDyslexiaReadingModeEnabled")

        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        #expect(harness.server.sends.count == 1)
        #expect(harness.server.sends[0].changes.map(\.key) == ["dyslexia"])
        #expect(harness.server.sends[0].cursor == harness.store.load(userId: 1).cursor)
        #expect(harness.store.load(userId: 1).outbox.isEmpty)

        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        #expect(harness.server.sends.count == 1)                                      // nothing left to send
    }

    @Test("Removing a setting on the device sends a delete; colour vision 'none' reads as no setting")
    func deleteAndNone() async throws {
        let harness = try makeHarness()
        harness.defaults.set("tritanopia", forKey: "ihCvdMode")
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)       // claims and sends the set
        harness.defaults.set("none", forKey: "ihCvdMode")                              // the app's own word for "off"

        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        let last = try #require(harness.server.sends.last?.changes.first)
        #expect(last.operation == .delete)
        #expect(last.value == nil)
        #expect(harness.local.read(try #require(ProfileSettingBindings.binding(id: "appearance.cvd"))) == nil)
    }

    @Test("A setting still waiting to be sent is not overwritten by a value read from the account")
    func outboxWinsOverAccount() async throws {
        let harness = try makeHarness()
        harness.server.onSend { _ in throw APIError.offline }                          // sending fails: the change stays queued
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        harness.defaults.set("light", forKey: "ihAppearanceTheme")
        let failed = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        #expect(failed == .failed)
        #expect(harness.store.load(userId: 1).outbox["appearance.theme"] != nil)

        harness.server.queueRead(ProfileSyncReply(cursor: 9, changes: [change("appearance.theme", .string("dark"), version: 9)]))
        harness.server.onSend { ProfileSyncReply(cursor: $0.cursor) }
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        #expect(harness.defaults.string(forKey: "ihAppearanceTheme") == "light")       // the person's newer choice stayed
        #expect(harness.server.sends.last?.changes.first?.value == .string("light"))   // and it was sent
    }

    @Test("An invalid value from the account, and a setting this build does not map, are left alone")
    func invalidAndUnmappedAreIgnored() async throws {
        let harness = try makeHarness()
        harness.server.queueRead(ProfileSyncReply(cursor: 3, changes: [
            change("appearance.theme", .string("neon"), version: 1),
            change("appearance.fontSize", .int(20), version: 2),
            change("reading.textScale", .double(99), version: 3)
        ]))

        let outcome = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        #expect(outcome == .done(changedLocal: false))
        #expect(harness.defaults.object(forKey: "ihAppearanceTheme") == nil)
        #expect(harness.defaults.object(forKey: "ihLyricsTextScale") == nil)
        #expect(harness.store.load(userId: 1).cursor == 3)                              // the cursor still moves on
    }

    @Test("A removal on the account resets the setting on the device")
    func accountRemovalResets() async throws {
        let harness = try makeHarness()
        harness.defaults.set("dark", forKey: "ihAppearanceTheme")
        harness.server.queueRead(ProfileSyncReply(cursor: 2, changes: [change("appearance.theme", nil, version: 2, deleted: true)]))

        let outcome = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        #expect(outcome == .done(changedLocal: true))
        #expect(harness.defaults.object(forKey: "ihAppearanceTheme") == nil)
    }

    @Test("A refusal the server will never accept drops the item and is not retried for ever")
    func permanentRejectionDropsItem() async throws {
        let harness = try makeHarness()
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        harness.defaults.set(false, forKey: "ihLyricsShowChords")                      // a real change from the unset baseline
        harness.server.onSend { ProfileSyncReply(cursor: $0.cursor, rejected: [ProfileSyncRejection(section: "reading", key: "showChords", reason: "invalid_value")]) }

        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        let sendsAfterFirst = harness.server.sends.count
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        #expect(sendsAfterFirst == 1)
        #expect(harness.server.sends.count == sendsAfterFirst)
        #expect(harness.store.load(userId: 1).outbox.isEmpty)
    }

    @Test("409 not switched on stops with .off; 409 saving paused keeps the outbox")
    func refusalsDecideByReasonWord() async throws {
        let off = try makeHarness()
        off.server.onSend { _ in throw APIError.server(status: 409, message: "profile_sync_disabled") }
        off.defaults.set("dark", forKey: "ihAppearanceTheme")
        _ = await off.engine.sync(userId: 1, transport: off.server.transport)
        #expect(await off.engine.sync(userId: 1, transport: off.server.transport) == .off)

        let paused = try makeHarness()
        paused.server.onSend { _ in throw APIError.server(status: 409, message: "account_writes_off_here") }
        paused.defaults.set("dark", forKey: "ihAppearanceTheme")
        let outcome = await paused.engine.sync(userId: 1, transport: paused.server.transport)
        #expect(outcome == .paused)
        #expect(paused.store.load(userId: 1).outbox["appearance.theme"] != nil)
    }

    @Test("A 413 halves the batch and a single change the server will never take is dropped")
    func tooBigHalvesThenDrops() async throws {
        let harness = try makeHarness()
        harness.defaults.set("dark", forKey: "ihAppearanceTheme")
        harness.defaults.set("deuteranopia", forKey: "ihCvdMode")
        harness.server.onSend { _ in throw APIError.server(status: 413, message: "too_many_changes") }

        let outcome = await harness.engine.sync(userId: 1, transport: harness.server.transport)

        #expect(outcome == .done(changedLocal: false))
        #expect(harness.server.sends.first?.changes.count == 2)                         // first the whole batch...
        #expect(harness.server.sends.contains { $0.changes.count == 1 })                 // ...then half...
        #expect(harness.store.load(userId: 1).outbox.isEmpty)                            // ...until each lone change was dropped
    }

    @Test("Each person has their own notebook: one person's waiting changes are never sent as another's")
    func notebooksArePerPerson() async throws {
        let harness = try makeHarness()
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        harness.server.onSend { _ in throw APIError.offline }
        harness.defaults.set("dark", forKey: "ihAppearanceTheme")
        _ = await harness.engine.sync(userId: 1, transport: harness.server.transport)
        #expect(harness.store.load(userId: 1).outbox["appearance.theme"] != nil)
        #expect(harness.store.load(userId: 2).outbox.isEmpty)
        harness.store.clear(userId: 1)
        #expect(harness.store.load(userId: 1) == ProfileSyncState())
    }

    @Test("A second sync while one is running does nothing")
    func oneAtATime() async throws {
        let harness = try makeHarness()
        let gate = AsyncGate()
        let slow = ProfileSyncTransport(
            read: { cursor in await gate.wait(); return ProfileSyncReply(cursor: cursor) },
            send: { ProfileSyncReply(cursor: $0.cursor) }
        )
        // Take the pieces out of the harness first, so the background task does not share the whole harness.
        let engine = harness.engine
        let realTransport = harness.server.transport
        async let first = engine.sync(userId: 1, transport: slow)
        while !(await gate.hasWaiter) { await Task.yield() }                     // the first sync is now inside its read
        let second = await engine.sync(userId: 1, transport: realTransport)
        await gate.open()
        #expect(second == .busy)
        #expect(await first == .done(changedLocal: false))
    }
}

/// A one-shot gate: `wait()` suspends until `open()` is called.
actor AsyncGate {
    private var isOpen = false
    private var waiters: [CheckedContinuation<Void, Never>] = []

    func wait() async {
        if isOpen { return }
        await withCheckedContinuation { waiters.append($0) }
    }

    var hasWaiter: Bool { !waiters.isEmpty }

    func open() {
        isOpen = true
        waiters.forEach { $0.resume() }
        waiters.removeAll()
    }
}
