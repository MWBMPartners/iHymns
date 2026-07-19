// RemoteControlCoordinator+Persistence.swift
// IHFeatures
//
// ELI5: The coordinator's own "remember this TV" bookkeeping — writing down
// a freshly-paired TV's token, and refreshing "we just talked to this TV
// again" on every reconnect — moved into its own small file so the main
// coordinator file has room to grow.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §2, fact 12 —
// BINDING) AND #1425 — BOTH the PR-11 watch-relay hooks and the PR-14
// Service-Mode-mirror hook independently pushed the coordinator over the
// 400-line LOC budget, so both needed this exact extraction; on integration
// they resolve to this one file (the method bodies are byte-identical). A
// PURE relocation out of `RemoteControlCoordinator.swift:363-392` —
// `persistPaired(token:resolved:)` and `touchLastConnected()` move here
// BYTE-IDENTICALLY in body, the same "LOC-budget relocation" the
// `+UIPhase.swift` file already established (#1424 precedent). The one
// mechanical change a cross-file move requires: `store`/`rebuildRows()`
// promote from `private` to plain (internal) access on the main type — the
// same "internal (not private) so this sibling file can reach it" pattern
// `RemoteControlCoordinator.swift`'s own doc comments already use for
// `session`/`currentTVName`/`savedRecords` (`+ManualConnect.swift`'s
// precedent). `RemoteControlUIStateTests` (unaffected — it only exercises
// `uiPhase(after:current:tvName:)`) and every other existing suite stay
// green, unchanged, across this commit.
//
// This is DISTINCT from the new `PairedTVStoring.touchLastConnected(
// fingerprint:resolvedAddress:now:)` protocol extension
// (`Sources/IHLive/WatchRelay/PairedTVStoring+Touch.swift`) the headless
// driver uses — that one takes its fingerprint/address/`now` as explicit
// parameters so it works with no `RemoteControlCoordinator` in scope at
// all; this method reads `currentFingerprint`/`session` directly, exactly
// as it always has. The coordinator does not adopt the shared helper here
// (spec §2's file table: "the coordinator MAY adopt later").
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
