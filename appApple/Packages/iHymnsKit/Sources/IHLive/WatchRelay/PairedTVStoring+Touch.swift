// PairedTVStoring+Touch.swift
// IHLive/WatchRelay
//
// ELI5: "We just successfully talked to this TV again — jot down that it
// was just now, and remember the address that worked" — the same little
// bit of housekeeping the on-screen remote already does for itself, made
// reusable so the background driver can do it too.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §2 —
// `PairedTVStoring+Touch.swift`). An ADDITIVE `PairedTVStoring` protocol
// extension, built ONLY from that protocol's own existing `record(
// forFingerprint:)`/`save(_:)` methods (`PairedTVStore.swift`) — so this
// file lives in the NEW `WatchRelay/` folder, not `LANRemote/`, keeping that
// folder's ZERO-diff guarantee for this PR (D-19) while still giving
// `HeadlessRelayDriver` (`IHFeatures`) the exact upsert
// `RemoteControlCoordinator.touchLastConnected()` (`IHFeatures`) already
// performs inline on every first-`.controlling`-arrival. The coordinator
// itself is NOT changed to call this shared helper in this PR — only the
// driver does — since `RemoteControlCoordinator.swift` is a byte-budget-
// constrained file this PR does not otherwise touch beyond the six call
// -lines spec §4.3 lists.
import Foundation

extension PairedTVStoring {
    /// Refreshes `lastConnectedAt`/`lastAddress` on the saved record for
    /// `fingerprint` — a no-op if the record is gone (forgotten between the
    /// fast-path resolve and this call; theoretically possible, never
    /// observed in practice since nothing else can delete a record the
    /// driver just successfully reconnected to, mid-burst).
    ///
    /// ELI5: "We just reached this TV again — write down when, and where."
    public func touchLastConnected(fingerprint: String, resolvedAddress: LANRemoteResolvedAddress?, now: Date) async {
        guard var record = await record(forFingerprint: fingerprint) else { return }
        record.lastConnectedAt = now
        if let resolvedAddress {
            record.lastAddress = resolvedAddress
        }
        await save(record)
    }
}
