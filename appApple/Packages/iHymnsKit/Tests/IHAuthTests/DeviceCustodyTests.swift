// DeviceCustodyTests.swift
// IHAuthTests
//
// ELI5: Checks the small pieces that give each device its own sign-in
// identity: the install id (made once, then kept), and the device's notebook
// ("signed out here", swapped shared tokens, dead keys, each capped at ten).
//
// DETAILED: PR 3b step I2a (#2180). Everything here runs against
// `InMemoryTokenStore` (the pretend safe), because a real Keychain cannot be
// exercised by `swift test` on a CI runner (no unlocked keychain; plan section
// 1, item 15). That means the Keychain ATTRIBUTES themselves (this-device-only,
// not synced) are NOT proven here: they are proven from the source by
// `tests/test-apple-keychain-custody.js`, and only a real device can show
// that a backup does not carry them (plan section 5, check 4).
import Foundation
import Testing
@testable import IHAuth

@Suite("Device identity and notebook (I2a)")
struct DeviceCustodyTests {

    @Test("The install id is made on first use and then stays the same")
    func installIdIsStable() async throws {
        let store = InMemoryTokenStore()
        let identity = DeviceIdentity(store: store)

        let first = try await identity.installId()
        let second = try await identity.installId()

        #expect(first == second)
        #expect(try await store.load() == first)
        // 36 characters of letters, digits and dashes: inside the server's 8...64 rule.
        #expect(DeviceIdentity.isUsable(first))
    }

    @Test("A second copy of the actor over the same store sees the same id (it survives a relaunch)")
    func installIdSurvivesRelaunch() async throws {
        let store = InMemoryTokenStore()
        let before = try await DeviceIdentity(store: store).installId()
        let after = try await DeviceIdentity(store: store).installId()
        #expect(before == after)
    }

    @Test("A stored value the server would drop is replaced, not sent")
    func unusableStoredIdIsReplaced() async throws {
        let store = InMemoryTokenStore()
        try await store.save("x") // too short for the server's rule
        let id = try await DeviceIdentity(store: store).installId()
        #expect(id != "x")
        #expect(DeviceIdentity.isUsable(id))
    }

    @Test("isUsable mirrors the server's 8 to 64 letters, digits and dashes")
    func usabilityRule() {
        #expect(DeviceIdentity.isUsable("abcd-1234"))
        #expect(!DeviceIdentity.isUsable("short"))
        #expect(!DeviceIdentity.isUsable(String(repeating: "a", count: 65)))
        #expect(!DeviceIdentity.isUsable("has space in it"))
        #expect(!DeviceIdentity.isUsable("under_score_1"))
    }

    @Test("A fresh notebook says nothing has happened here")
    func freshNotebookIsEmpty() async {
        let state = DeviceSignInState(store: InMemoryTokenStore())
        #expect(await state.record() == DeviceSignInRecord())
    }

    @Test("signedOutHere is set by a sign-out and cleared only when told to")
    func signedOutMark() async {
        let state = DeviceSignInState(store: InMemoryTokenStore())
        await state.setSignedOutHere(true)
        #expect(await state.record().signedOutHere)
        await state.setSignedOutHere(false)
        #expect(await state.record().signedOutHere == false)
    }

    @Test("Notes are remembered across a relaunch (a new actor over the same store)")
    func notesSurviveRelaunch() async {
        let store = InMemoryTokenStore()
        let first = DeviceSignInState(store: store)
        await first.setSignedOutHere(true)
        await first.markLegacySwapped(hash: "aaa")
        await first.markKeyDead(hash: "bbb")

        let second = DeviceSignInState(store: store)
        #expect(await second.record().signedOutHere)
        #expect(await second.isLegacySwapped(hash: "aaa"))
        #expect(await second.isKeyDead(hash: "bbb"))
        #expect(await second.isKeyDead(hash: "aaa") == false)
    }

    @Test("Each fingerprint list keeps at most ten, dropping the oldest, with no duplicates")
    func listsAreCapped() async {
        let state = DeviceSignInState(store: InMemoryTokenStore())
        for i in 0..<12 { await state.markKeyDead(hash: "k\(i)") }
        let keys = await state.record().deadKeyHashes
        #expect(keys.count == 10)
        #expect(keys.first == "k2")
        #expect(keys.last == "k11")

        await state.markKeyDead(hash: "k5") // already there: moves to the end, no duplicate
        let again = await state.record().deadKeyHashes
        #expect(again.count == 10)
        #expect(again.last == "k5")
        #expect(again.filter { $0 == "k5" }.count == 1)
    }

    @Test("A garbled stored notebook reads as empty, never an error")
    func garbledNotebookReadsEmpty() async throws {
        let store = InMemoryTokenStore()
        try await store.save("{not json")
        #expect(await DeviceSignInState(store: store).record() == DeviceSignInRecord())
    }

    @Test("A notebook saved before automaticSignInOff existed still decodes and keeps signed out here")
    func olderNotebookStillDecodes() async throws {
        let store = InMemoryTokenStore()
        try await store.save(#"{"signedOutHere":true,"swappedLegacyHashes":["a"],"deadKeyHashes":[]}"#)
        let record = await DeviceSignInState(store: store).record()
        #expect(record.signedOutHere)
        #expect(record.swappedLegacyHashes == ["a"])
        #expect(record.automaticSignInOff == false)
    }

    @Test("The automatic sign-in choice is remembered")
    func automaticSignInChoice() async {
        let state = DeviceSignInState(store: InMemoryTokenStore())
        await state.setAutomaticSignInOff(true)
        #expect(await state.record().automaticSignInOff)
        await state.setAutomaticSignInOff(false)
        #expect(await state.record().automaticSignInOff == false)
    }

    @Test("The fingerprint is stable, 64 hex characters, and not the secret")
    func fingerprintShape() {
        let a = TokenFingerprint.sha256Hex("secret-token")
        #expect(a == TokenFingerprint.sha256Hex("secret-token"))
        #expect(a != TokenFingerprint.sha256Hex("secret-token2"))
        #expect(a.count == 64)
        #expect(!a.contains("secret"))
        // The well-known SHA-256 of the empty string pins the algorithm.
        #expect(TokenFingerprint.sha256Hex("") == "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855")
    }
}
