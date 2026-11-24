// InMemoryTokenStore.swift
// IHAuthTests
//
// ELI5: A pretend "safe" that just remembers the token in a plain variable
// — used ONLY by tests, so they don't need to touch the real device
// Keychain (which often isn't reliably available in a headless CI/test
// runner — see the doc comment on `TokenStoring` in the main module for
// why this substitution is the whole point of that protocol).
//
// DETAILED (PR 3b review fixes): it can now be told to FAIL a save, load or
// delete on demand (so a test can say "the Keychain refuses to save"), and it
// can write each save / delete into a shared `OperationLog`, so a test can
// check the ORDER things happened in across several stores ("the notebook was
// written before the token was deleted").
import Foundation
import IHAuth

/// An ordered list of "store.operation" lines shared between several stores.
final class OperationLog: @unchecked Sendable {
    private let lock = NSLock()
    private var lines: [String] = []

    func add(_ line: String) {
        lock.lock(); defer { lock.unlock() }
        lines.append(line)
    }

    var all: [String] { lock.lock(); defer { lock.unlock() }; return lines }
    func reset() { lock.lock(); defer { lock.unlock() }; lines = [] }
}

actor InMemoryTokenStore: TokenStoring {
    /// What a forced failure throws.
    struct Failure: Error, Equatable {}

    private var token: String?
    private let name: String
    private let log: OperationLog?
    private var failSaves = false
    private var failLoads = false
    private var failDeletes = false

    init(name: String = "store", log: OperationLog? = nil) {
        self.name = name
        self.log = log
    }

    /// Turn forced failures on or off. Only the ones named are changed.
    func setFailing(saves: Bool? = nil, loads: Bool? = nil, deletes: Bool? = nil) {
        if let saves { failSaves = saves }
        if let loads { failLoads = loads }
        if let deletes { failDeletes = deletes }
    }

    func save(_ token: String) async throws {
        if failSaves { throw Failure() }
        log?.add(name + ".save")
        self.token = token
    }

    func load() async throws -> String? {
        if failLoads { throw Failure() }
        return token
    }

    func delete() async throws {
        if failDeletes { throw Failure() }
        log?.add(name + ".delete")
        token = nil
    }
}
