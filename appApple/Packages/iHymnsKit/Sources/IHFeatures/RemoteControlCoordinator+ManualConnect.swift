// RemoteControlCoordinator+ManualConnect.swift
// IHFeatures
//
// ELI5: The coordinator's half of "connect to a TV I typed the address
// of" — parses what the human typed, starts the TOFU connect, and decides
// what to do once it sees a fingerprint: silently reconnect if we already
// trust that TV, or show the confirm-it's-yours screen if we've never seen
// it before.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §6.5). Never imports
// `Network` (this repo's tripwire for IHFeatures files, spec §1's D-14) —
// every primitive here is a host `String` + port `UInt16`; the `NWEndpoint`
// itself is built inside `IHLive`'s `RemoteControlSession+ManualConnect.swift`.
// `RemotePairingEntryResolver.manualResolution` (`IHLive`) is the ONE place
// the known-TV-vs-unknown-TV decision is made — this file never improvises
// that lookup itself.
import Foundation
import IHLive
import IHLog

extension RemoteControlCoordinator {
    /// `ManualConnectSheet`'s "Connect" action — spec §6.2/§6.5.
    ///
    /// ELI5: "Try to connect to whatever's at this address."
    public func connectByAddress(hostInput: String, portInput: String?) {
        switch LANRemoteManualAddress.parse(hostInput: hostInput, portInput: portInput) {
        case .failure(let error):
            notice = Self.manualParseNotice(for: error)
        case .success(let parsed):
            notice = nil
            currentTVName = parsed.host
            currentFingerprint = nil
            isManualConnectInFlight = true
            lastManualParsed = parsed
            // #1423 — handoff hook 4 (spec §4.3): retire any live headless
            // linger BEFORE this manual-connect entry attaches.
            Task {
                await self.relayRetireHeadless()
                await self.session.attachByAddress(host: parsed.host, port: parsed.port, displayName: parsed.host)
            }
        }
    }

    /// `FingerprintConfirmView`'s "It Matches — Continue" — spec §6.3.
    ///
    /// ELI5: "Yes, that fingerprint matches my TV — go ahead."
    public func confirmFingerprint() {
        Task { await session.confirmObservedFingerprint() }
    }

    /// The §4.3 known-TV shortcut — decided by the PURE resolver, never
    /// improvised here. Called from `apply(_:)`'s
    /// `.awaitingFingerprintConfirmation` case.
    func handleFingerprintObservation(_ fingerprint: String) async {
        guard let parsed = lastManualParsed else {
            // Defensive — unreachable in practice: `connectByAddress`
            // always sets `lastManualParsed` before `attachByAddress` can
            // ever reach `.awaitingFingerprintConfirmation`. Treat as
            // unknown so the interstitial still gates a code ceremony
            // rather than silently no-op'ing on a TV nobody has confirmed.
            currentFingerprint = fingerprint
            uiPhase = .confirmingFingerprint(tvName: currentTVName ?? "this TV", fingerprintHex: fingerprint)
            return
        }

        let resolution = RemotePairingEntryResolver.manualResolution(
            observedFingerprint: fingerprint, host: parsed.host, port: parsed.port,
            displayName: parsed.host, saved: savedRecords
        )
        switch resolution {
        case .knownTV(let target):
            // A TV we ALREADY trust, reached at a new/rediscovered address
            // — the ordinary PINNED fast path. No interstitial, no
            // ceremony, no new trust decision (spec §4.3).
            IHLog.remote.notice("lanremote.manual known-tv-shortcut")
            currentTVName = target.tvName
            currentFingerprint = fingerprint
            Task { await session.attach(to: target) }
        case .unknownTV:
            currentTVName = parsed.host
            currentFingerprint = fingerprint
            uiPhase = .confirmingFingerprint(tvName: parsed.host, fingerprintHex: fingerprint)
        }
    }

    /// The manual-connect-specific `.pairingEnded(.connectionTornDown)`
    /// copy — spec §6.5.
    func manualConnectionTornDownNotice() -> String {
        guard let parsed = lastManualParsed else {
            return "Pairing didn't complete. Ask the operator to open pairing on the TV again."
        }
        return "Couldn't reach \(parsed.host):\(parsed.port). Check the address, and that iHymns is open on the TV."
    }

    /// `LANRemoteManualAddress.ParseError` → the sheet's inline footer copy
    /// — an exhaustive `switch` so a new error case is a compile error
    /// here, never a silently-missing message.
    private static func manualParseNotice(for error: LANRemoteManualAddress.ParseError) -> String {
        switch error {
        case .empty:
            return "Enter the TV's address."
        case .unsupportedScheme:
            return "Just the address, no “http://”."
        case .invalidHost:
            return "That doesn't look like a valid address."
        case .invalidPort:
            return "That port isn't valid — use a number between 1 and 65535."
        }
    }
}
