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
                lock.lock(); defer { lock.unlock() }
                readLog.append(cursor)
                return readQueue.isEmpty ? ProfileSyncReply(cursor: cursor) : readQueue.removeFirst()
            },
            send: { [self] request in
                lock.lock()
                sendLog.append(request)
                let handler = sendHandler
                lock.unlock()
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
        let h = try makeHarness()
        h.server.queueRead(ProfileSyncReply(cursor: 5, changes: [
            change("appearance.theme", .string("dark"), version: 4),
            change("reading.textScale", .double(1.5), version: 5)
        ]))

        let outcome = await h.engine.sync(userId: 1, transport: h.server.transport)

        #expect(outcome == .done(changedLocal: true))
        #expect(h.server.reads == [0])
        #expect(h.defaults.string(forKey: "ihAppearanceTheme") == "dark")
        #expect(h.defaults.double(forKey: "ihLyricsTextScale") == 1.5)
        #expect(h.server.sends.isEmpty)               // what came FROM the account is never sent back
        #expect(h.store.load(userId: 1).claimed)
        #expect(h.store.load(userId: 1).cursor == 5)
    }

    @Test("First sync claims a device value the account never heard of, but only where the entry allows claiming")
    func claimOnlyWhereAllowed() async throws {
        let h = try makeHarness()
        h.defaults.set("deuteranopia", forKey: "ihCvdMode")      // appearance.cvd: claim true
        h.defaults.set(2.0, forKey: "ihLyricsTextScale")         // reading.textScale: claim false

        _ = await h.engine.sync(userId: 1, transport: h.server.transport)

        let sent = h.server.sends.flatMap(\.changes)
        #expect(sent.map { $0.section + "." + $0.key } == ["appearance.cvd"])
        #expect(sent.first?.value == .string("deuteranopia"))
        #expect(sent.first?.op == .set)
    }

    @Test("A later change on the device is sent once, acknowledged, and not sent again")
    func localChangeIsSentOnce() async throws {
        let h = try makeHarness()
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)     // first sync: nothing to send
        h.defaults.set(true, forKey: "ihDyslexiaReadingModeEnabled")

        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        #expect(h.server.sends.count == 1)
        #expect(h.server.sends[0].changes.map(\.key) == ["dyslexia"])
        #expect(h.server.sends[0].cursor == h.store.load(userId: 1).cursor)
        #expect(h.store.load(userId: 1).outbox.isEmpty)

        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        #expect(h.server.sends.count == 1)                                      // nothing left to send
    }

    @Test("Removing a setting on the device sends a delete; colour vision 'none' reads as no setting")
    func deleteAndNone() async throws {
        let h = try makeHarness()
        h.defaults.set("tritanopia", forKey: "ihCvdMode")
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)       // claims and sends the set
        h.defaults.set("none", forKey: "ihCvdMode")                              // the app's own word for "off"

        _ = await h.engine.sync(userId: 1, transport: h.server.transport)

        let last = try #require(h.server.sends.last?.changes.first)
        #expect(last.op == .delete)
        #expect(last.value == nil)
        #expect(h.local.read(try #require(ProfileSettingBindings.binding(id: "appearance.cvd"))) == nil)
    }

    @Test("A setting still waiting to be sent is not overwritten by a value read from the account")
    func outboxWinsOverAccount() async throws {
        let h = try makeHarness()
        h.server.onSend { _ in throw APIError.offline }                          // sending fails: the change stays queued
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        h.defaults.set("light", forKey: "ihAppearanceTheme")
        let failed = await h.engine.sync(userId: 1, transport: h.server.transport)
        #expect(failed == .failed)
        #expect(h.store.load(userId: 1).outbox["appearance.theme"] != nil)

        h.server.queueRead(ProfileSyncReply(cursor: 9, changes: [change("appearance.theme", .string("dark"), version: 9)]))
        h.server.onSend { ProfileSyncReply(cursor: $0.cursor) }
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)

        #expect(h.defaults.string(forKey: "ihAppearanceTheme") == "light")       // the person's newer choice stayed
        #expect(h.server.sends.last?.changes.first?.value == .string("light"))   // and it was sent
    }

    @Test("An invalid value from the account, and a setting this build does not map, are left alone")
    func invalidAndUnmappedAreIgnored() async throws {
        let h = try makeHarness()
        h.server.queueRead(ProfileSyncReply(cursor: 3, changes: [
            change("appearance.theme", .string("neon"), version: 1),
            change("appearance.fontSize", .int(20), version: 2),
            change("reading.textScale", .double(99), version: 3)
        ]))

        let outcome = await h.engine.sync(userId: 1, transport: h.server.transport)

        #expect(outcome == .done(changedLocal: false))
        #expect(h.defaults.object(forKey: "ihAppearanceTheme") == nil)
        #expect(h.defaults.object(forKey: "ihLyricsTextScale") == nil)
        #expect(h.store.load(userId: 1).cursor == 3)                              // the cursor still moves on
    }

    @Test("A removal on the account resets the setting on the device")
    func accountRemovalResets() async throws {
        let h = try makeHarness()
        h.defaults.set("dark", forKey: "ihAppearanceTheme")
        h.server.queueRead(ProfileSyncReply(cursor: 2, changes: [change("appearance.theme", nil, version: 2, deleted: true)]))

        let outcome = await h.engine.sync(userId: 1, transport: h.server.transport)

        #expect(outcome == .done(changedLocal: true))
        #expect(h.defaults.object(forKey: "ihAppearanceTheme") == nil)
    }

    @Test("A refusal the server will never accept drops the item and is not retried for ever")
    func permanentRejectionDropsItem() async throws {
        let h = try makeHarness()
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        h.defaults.set(false, forKey: "ihLyricsShowChords")                      // a real change from the unset baseline
        h.server.onSend { ProfileSyncReply(cursor: $0.cursor, rejected: [ProfileSyncRejection(section: "reading", key: "showChords", reason: "invalid_value")]) }

        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        let sendsAfterFirst = h.server.sends.count
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)

        #expect(sendsAfterFirst == 1)
        #expect(h.server.sends.count == sendsAfterFirst)
        #expect(h.store.load(userId: 1).outbox.isEmpty)
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
        let h = try makeHarness()
        h.defaults.set("dark", forKey: "ihAppearanceTheme")
        h.defaults.set("deuteranopia", forKey: "ihCvdMode")
        h.server.onSend { _ in throw APIError.server(status: 413, message: "too_many_changes") }

        let outcome = await h.engine.sync(userId: 1, transport: h.server.transport)

        #expect(outcome == .done(changedLocal: false))
        #expect(h.server.sends.first?.changes.count == 2)                         // first the whole batch...
        #expect(h.server.sends.contains { $0.changes.count == 1 })                 // ...then half...
        #expect(h.store.load(userId: 1).outbox.isEmpty)                            // ...until each lone change was dropped
    }

    @Test("Each person has their own notebook: one person's waiting changes are never sent as another's")
    func notebooksArePerPerson() async throws {
        let h = try makeHarness()
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        h.server.onSend { _ in throw APIError.offline }
        h.defaults.set("dark", forKey: "ihAppearanceTheme")
        _ = await h.engine.sync(userId: 1, transport: h.server.transport)
        #expect(h.store.load(userId: 1).outbox["appearance.theme"] != nil)
        #expect(h.store.load(userId: 2).outbox.isEmpty)
        h.store.clear(userId: 1)
        #expect(h.store.load(userId: 1) == ProfileSyncState())
    }

    @Test("A second sync while one is running does nothing")
    func oneAtATime() async throws {
        let h = try makeHarness()
        let gate = AsyncGate()
        let slow = ProfileSyncTransport(
            read: { cursor in await gate.wait(); return ProfileSyncReply(cursor: cursor) },
            send: { ProfileSyncReply(cursor: $0.cursor) }
        )
        async let first = h.engine.sync(userId: 1, transport: slow)
        while !(await gate.hasWaiter) { await Task.yield() }                     // the first sync is now inside its read
        let second = await h.engine.sync(userId: 1, transport: h.server.transport)
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
