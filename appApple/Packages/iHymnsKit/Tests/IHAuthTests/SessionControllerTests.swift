// SessionControllerTests.swift
// IHAuthTests
//
// ELI5: Signs a fake user in, checks the state flips to "signed in," signs
// them out, checks it flips back — using the in-memory fake safe so no real
// Keychain is touched.
import Testing
@testable import IHAuth

@Suite("SessionController")
struct SessionControllerTests {

    @Test("Starts signed out when no token is stored")
    func startsSignedOut() async throws {
        let controller = SessionController(tokenStore: InMemoryTokenStore())
        try await controller.restoreFromStorage()
        #expect(await controller.state == .signedOut)
    }

    @Test("Restores signedIn state from an already-stored token")
    func restoresExistingToken() async throws {
        let store = InMemoryTokenStore()
        try await store.save("existing-token")

        let controller = SessionController(tokenStore: store)
        try await controller.restoreFromStorage()

        #expect(await controller.state == .signedIn(token: "existing-token"))
    }

    @Test("signIn persists the token and flips state to signedIn")
    func signInPersistsToken() async throws {
        let store = InMemoryTokenStore()
        let controller = SessionController(tokenStore: store)

        try await controller.signIn(token: "fresh-token")

        #expect(await controller.state == .signedIn(token: "fresh-token"))
        #expect(try await store.load() == "fresh-token")
    }

    @Test("signOut clears the token and flips state to signedOut")
    func signOutClearsToken() async throws {
        let store = InMemoryTokenStore()
        let controller = SessionController(tokenStore: store)
        try await controller.signIn(token: "fresh-token")

        try await controller.signOut()

        #expect(await controller.state == .signedOut)
        #expect(try await store.load() == nil)
    }

    @Test("stateUpdates publishes every transition in order")
    func stateUpdatesPublishesTransitions() async throws {
        let controller = SessionController(tokenStore: InMemoryTokenStore())
        var iterator = controller.stateUpdates.makeAsyncIterator()

        try await controller.signIn(token: "a")
        let first = await iterator.next()
        #expect(first == .signedIn(token: "a"))

        try await controller.signOut()
        let second = await iterator.next()
        #expect(second == .signedOut)
    }
}
