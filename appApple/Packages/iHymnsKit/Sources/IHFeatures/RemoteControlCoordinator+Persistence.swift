// RemoteControlCoordinator+Persistence.swift
// IHFeatures
//
// ELI5: The two little "remember this TV" jobs — writing a freshly-paired TV
// into the Keychain-backed store, and bumping "last seen just now" on one
// that reconnected — moved out of the big coordinator file so it stays under
// the line budget. Nothing about WHAT they do changed.
//
// DETAILED: #1425. A PURE relocation out of `RemoteControlCoordinator.swift`
// (LOC budget — the PR-14 Service-Mode-mirror hook pushed the core file over
// 400 lines), mirroring the `+UIPhase.swift` (#1424) precedent already noted
// at the end of the core file. `persistPaired(token:resolved:)` +
// `touchLastConnected()` move here byte-identically from the PR-7/PR-8 shape;
// the existing coordinator tests exercise both paths UNCHANGED.
import Foundation
import IHLive

extension RemoteControlCoordinator {
    func persistPaired(token: String, resolved: LANRemoteResolvedAddress?) async {
        guard let fingerprint = currentFingerprint, let name = currentTVName else { return }
        let now = Date()
        let existing = await store.record(forFingerprint: fingerprint)
        let record = PairedTVRecord(
            fingerprintHex: fingerprint, name: name, token: token,
            lastAddress: resolved ?? existing?.lastAddress,
            pairedAt: existing?.pairedAt ?? now, lastConnectedAt: now
        )
        await store.save(record)
        savedRecords = await store.listPairedTVs()
        rebuildRows()
    }

    /// On each `.controlling` first-arrival after an attach/reconnect,
    /// update `lastConnectedAt`/`lastAddress` on the EXISTING saved record
    /// (spec §5.2) — distinct from `persistPaired`, which only runs once per
    /// FRESH ceremony. A fast-path reconnect (no `.paired` event at all)
    /// still needs its `lastConnectedAt` refreshed, which is what this call
    /// is for.
    func touchLastConnected() async {
        guard let fingerprint = currentFingerprint, var record = await store.record(forFingerprint: fingerprint) else { return }
        record.lastConnectedAt = Date()
        if let resolved = await session.currentResolvedAddress() {
            record.lastAddress = resolved
        }
        await store.save(record)
        savedRecords = await store.listPairedTVs()
        rebuildRows()
    }
}
