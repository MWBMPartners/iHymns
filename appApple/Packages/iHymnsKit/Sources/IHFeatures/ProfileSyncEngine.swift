// ProfileSyncEngine.swift
// IHFeatures
//
// ELI5: The brain of the shared-settings sync. It reads what changed on the
// account and applies it to this device, notices what changed on this device
// and sends it, and never lets either side quietly overwrite the other's newer
// choice.
//
// DETAILED: PR 3b step I4 (#2180), a port of `js/modules/profile-sync.js` to
// the Apple app's settings. Order of one `sync`:
//   1. READ (pages of up to 500, at most 40 pages): the FIRST read of a person
//      on this device is a full read from 0 (whatever the saved cursor says),
//      because the claim below must know everything the account has ever held.
//      Each reply is applied: a value is validated against the bundled
//      registry and written to the device; a removal resets the setting; both
//      set the BASELINE (so it is never sent back) and are SKIPPED for a
//      setting still waiting in the outbox (the person's newer choice wins).
//      Settings the app does not map stay on the account untouched.
//   2. CLAIM (once, after the first full read): a setting the account has never
//      heard of (neither a value nor a removal) whose registry entry allows
//      claiming is left to be sent from what this device holds; one that does
//      not allow claiming adopts the device value as its baseline, so it is not
//      sent until the person changes it.
//   3. SCAN: every bound setting is compared with its baseline; a difference
//      becomes an outbox item (the newest change to a setting replaces the older).
//   4. SEND (batches of up to 200 changes and 48,000 characters, oldest first):
//      an item leaves the outbox only if it has not changed since it was sent. A
//      refusal the server will never accept (`unknown_key`, `invalid_value`, ...)
//      drops the item and records the device value as the baseline, so it is not
//      retried for ever (the value stays on the device). A 413 halves the batch.
//   An unexpected failure keeps everything for the next time. The server's
//   status plus reason word decide what to do, never its sentence (rule #35):
//   409 `profile_sync_disabled` / `profile_sync_not_ready` -> `.off` (do
//   nothing, stop trying), 409 `account_writes_off_here` -> `.paused` (the
//   outbox is kept).
// Only one sync runs at a time (`running`): a second call while one is in
// flight returns `.busy`, which keeps the notebook from being overwritten.
import Foundation
import IHAPI
import IHModels

/// How one `sync` ended.
///
/// ELI5: "Finished", "not switched on here", "saving paused", "try again later", or "already busy".
enum ProfileSyncOutcome: Sendable, Equatable {
    /// Finished. `changedLocal` says whether a setting on this device was changed from the account.
    case done(changedLocal: Bool)
    /// The site does not use this (yet): stop trying until told otherwise.
    case off
    /// Saving is paused on this site: the changes are kept and sent later.
    case paused
    /// A failure (offline, server trouble, ...): everything is kept for next time.
    case failed
    /// Another sync is already running.
    case busy
}

/// The two network calls the engine needs, as closures so a test can supply them.
///
/// ELI5: "How to ask the server", without the engine knowing it is `APIClient`.
struct ProfileSyncTransport: Sendable {
    let read: @Sendable (Int) async throws -> ProfileSyncReply
    let send: @Sendable (ProfileSyncRequest) async throws -> ProfileSyncReply
}

actor ProfileSyncEngine {
    /// The most changes in one send (the server refuses more with 413).
    static let maxChangesPerRequest = 200
    /// A send stays well under the server's 64 KiB body limit.
    static let maxBodyCharacters = 48 * 1024
    /// The most pages one read follows (the rest is read next time).
    static let maxPages = 40
    /// The most batches one sync sends.
    static let maxBatches = 20
    /// Refusals that will never succeed as sent, so the item must not stay queued.
    static let permanentRejections: Set<String> = [
        "unknown_key", "device_only", "not_profile_item", "invalid_value",
        "invalid_item_key", "value_too_large", "cap_reached", "not_opted_in"
    ]

    private let schema: ProfileSchema
    private let bindings: [ProfileSettingBinding]
    private let local: ProfileLocalSettings
    private let stateStore: ProfileSyncStateStore
    private let appVersion: String?
    private var running = false
    private var batchLimit = ProfileSyncEngine.maxChangesPerRequest

    init(
        schema: ProfileSchema,
        bindings: [ProfileSettingBinding] = ProfileSettingBindings.all,
        local: ProfileLocalSettings = ProfileLocalSettings(),
        stateStore: ProfileSyncStateStore = ProfileSyncStateStore(),
        appVersion: String? = nil
    ) {
        self.schema = schema
        self.bindings = bindings
        self.local = local
        self.stateStore = stateStore
        self.appVersion = appVersion
    }

    /// Reads, then scans and sends, for `userId`.
    func sync(userId: Int, transport: ProfileSyncTransport) async -> ProfileSyncOutcome {
        guard !running else { return .busy }
        running = true
        defer { running = false }

        var state = stateStore.load(userId: userId)
        var changedLocal = false
        do {
            try await pull(&state, transport, &changedLocal)
            scanLocalChanges(&state)
            stateStore.save(state, userId: userId)
            try await push(&state, userId: userId, transport, &changedLocal)
            stateStore.save(state, userId: userId)
            return .done(changedLocal: changedLocal)
        } catch let error as APIError {
            stateStore.save(state, userId: userId)
            if case .server(let status, let message) = error, status == 409 {
                if message == ProfileSyncReason.paused.rawValue { return .paused }
                if message == ProfileSyncReason.disabled.rawValue || message == ProfileSyncReason.notReady.rawValue { return .off }
            }
            return .failed
        } catch {
            stateStore.save(state, userId: userId)
            return .failed
        }
    }

    // MARK: - Reading

    private func pull(_ state: inout ProfileSyncState, _ transport: ProfileSyncTransport, _ changedLocal: inout Bool) async throws {
        let needFull = !state.claimed
        var from = needFull ? 0 : state.cursor
        var seen = Set<String>()
        for _ in 0..<Self.maxPages {
            let reply = try await transport.read(from)
            for change in reply.changes { seen.insert(change.section + "." + change.key) }
            if apply(reply, &state) { changedLocal = true }
            saveCursor(reply, &state)
            from = reply.cursor
            if !reply.more {
                if needFull { claimMissing(seen: seen, &state) }
                return
            }
        }
        // More pages than one sync follows: the rest is read next time (the cursor already moved on).
    }

    /// Applies one reply's changes to the device, except settings still waiting in the outbox.
    /// - Returns: whether any setting on the device changed.
    private func apply(_ reply: ProfileSyncReply, _ state: inout ProfileSyncState) -> Bool {
        var changed = false
        for change in reply.changes {
            let id = change.section + "." + change.key
            if state.outbox[id] != nil { continue }                     // the person's newer choice wins
            guard let binding = bindings.first(where: { $0.id == id }), let entry = schema.entry(id: id) else { continue }
            if change.deleted {
                if local.read(binding) != nil { changed = true }
                local.write(nil, to: binding)
                state.baseline[id] = ProfileBaselineEntry(value: nil)
            } else if let value = change.value, entry.validate(value) {
                if local.read(binding) != value { changed = true }
                local.write(value, to: binding)
                state.baseline[id] = ProfileBaselineEntry(value: value)
            }
            // A value this build cannot judge (or one that is not valid) is left on the account, untouched.
        }
        return changed
    }

    private func saveCursor(_ reply: ProfileSyncReply, _ state: inout ProfileSyncState) {
        guard reply.cursor >= 0 else { return }
        // The cursor only goes up, except when the server says it was too high (a restored backup).
        state.cursor = reply.resetCursor ? reply.cursor : Swift.max(state.cursor, reply.cursor)
    }

    /// After the first full read: decide, for each bound setting the account never mentioned, what the baseline is.
    private func claimMissing(seen: Set<String>, _ state: inout ProfileSyncState) {
        for binding in bindings where !seen.contains(binding.id) {
            guard let entry = schema.entry(id: binding.id) else { continue }
            // Claimable: the account holds nothing, so what the device holds will be sent by the scan.
            // Not claimable: adopt the device value, so nothing is sent until the person changes it.
            state.baseline[binding.id] = ProfileBaselineEntry(value: entry.isClaimable ? nil : local.read(binding))
        }
        state.claimed = true
    }

    // MARK: - Scanning

    /// Compares each bound setting with its baseline and queues the differences.
    private func scanLocalChanges(_ state: inout ProfileSyncState) {
        for binding in bindings {
            guard let entry = schema.entry(id: binding.id), entry.sync == "profile_sync", entry.scope == "account" else { continue }
            let id = binding.id
            if state.baseline[id] == nil {
                // Never agreed with the account: a claimable setting starts from "the account holds none",
                // anything else adopts what the device holds.
                state.baseline[id] = ProfileBaselineEntry(value: entry.isClaimable ? nil : local.read(binding))
            }
            let known = state.baseline[id]?.value
            let current = local.read(binding)
            if current == known {
                // Nothing differs now: an older queued change for this setting is stale (the person changed it back).
                state.outbox[id] = nil
                continue
            }
            if let current, !entry.validate(current) { continue }       // a corrupt local value is never sent
            let operation = current == nil ? "delete" : "set"
            if let queued = state.outbox[id], queued.operation == operation, queued.value == current { continue }
            state.seq += 1
            state.outbox[id] = ProfileOutboxItem(operation: operation, value: current, seq: state.seq)
        }
    }

    // MARK: - Sending

    private func push(_ state: inout ProfileSyncState, userId: Int, _ transport: ProfileSyncTransport, _ changedLocal: inout Bool) async throws {
        for _ in 0..<Self.maxBatches {
            let batch = nextBatch(state)
            if batch.isEmpty { return }
            let request = ProfileSyncRequest(
                schemaVersion: schema.schemaVersion,
                appVersion: appVersion,
                cursor: state.cursor,
                changes: batch.map(\.change)
            )
            let reply: ProfileSyncReply
            do {
                reply = try await transport.send(request)
            } catch APIError.server(let status, _) where status == 413 {
                // Too big for one request: send half as many. A single change the server will never take is dropped.
                if batch.count <= 1 {
                    let only = batch[0]
                    state.outbox[only.id] = nil
                    state.baseline[only.id] = ProfileBaselineEntry(value: local.read(only.binding))
                } else {
                    batchLimit = Swift.max(1, batch.count / 2)
                }
                continue
            }
            // The server has the changes: an item leaves the outbox only if it has not changed since it was sent.
            for item in batch where state.outbox[item.id]?.seq == item.seq {
                state.outbox[item.id] = nil
                state.baseline[item.id] = ProfileBaselineEntry(value: item.change.operation == .set ? item.change.value : nil)
            }
            // A change the server will never accept as sent: do not keep retrying it (the value stays on the device).
            for rejection in reply.rejected where Self.permanentRejections.contains(rejection.reason) {
                let id = rejection.section + "." + rejection.key
                if let binding = bindings.first(where: { $0.id == id }), batch.contains(where: { $0.id == id }) {
                    state.baseline[id] = ProfileBaselineEntry(value: local.read(binding))
                }
            }
            if apply(reply, &state) { changedLocal = true }
            saveCursor(reply, &state)
            if reply.more { try await pull(&state, transport, &changedLocal) }
            stateStore.save(state, userId: userId) // keep progress between batches
        }
    }

    private struct BatchItem {
        let id: String
        let binding: ProfileSettingBinding
        let change: ProfileSyncChange
        let seq: Int
    }

    /// The next batch from the outbox (oldest first), within the change and size limits.
    private func nextBatch(_ state: ProfileSyncState) -> [BatchItem] {
        var batch: [BatchItem] = []
        var size = 0
        for (id, item) in state.outbox.sorted(by: { $0.value.seq < $1.value.seq }) {
            guard let binding = bindings.first(where: { $0.id == id }) else { continue }
            let change = ProfileSyncChange(
                section: binding.section,
                key: binding.key,
                operation: item.operation == "set" ? .set : .delete,
                value: item.operation == "set" ? item.value : nil
            )
            let length = ((try? JSONEncoder().encode(change))?.count ?? 100) + 1
            if !batch.isEmpty && (size + length > Self.maxBodyCharacters || batch.count >= batchLimit) { break }
            batch.append(BatchItem(id: id, binding: binding, change: change, seq: item.seq))
            size += length
        }
        return batch
    }
}
