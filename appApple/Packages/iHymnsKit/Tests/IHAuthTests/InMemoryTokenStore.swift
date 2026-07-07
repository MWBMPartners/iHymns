// InMemoryTokenStore.swift
// IHAuthTests
//
// ELI5: A pretend "safe" that just remembers the token in a plain variable
// — used ONLY by tests, so they don't need to touch the real device
// Keychain (which often isn't reliably available in a headless CI/test
// runner — see the doc comment on `TokenStoring` in the main module for
// why this substitution is the whole point of that protocol).
import IHAuth

actor InMemoryTokenStore: TokenStoring {
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
