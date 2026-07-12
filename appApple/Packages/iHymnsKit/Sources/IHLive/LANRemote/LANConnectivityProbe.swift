// LANConnectivityProbe.swift
// IHLive/LANRemote
//
// ELI5: A single "is anything listening at this address?" check — connect,
// see if a TLS handshake completes, then hang up immediately. It never
// says a word of the actual remote-control language, so it can never
// accidentally start (or look like) a pairing attempt.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §5.1, Decision D-7 —
// BINDING). Deliberately NOT `RemoteSessionActor`: the tripwire caps this
// PR at ONE actor addition (`connectTrustingFirstUse`, `+TOFU.swift`); a
// diagnostic that spoke IHRP (even just `hello`) would consume one of the
// TV's 4 unpaired connection slots and light up the venue's own "a remote
// is trying to pair" indicator for a MERE reachability check — exactly the
// annoyance/attack-surface spec §8's threat-model table rules out. A
// single-shot `static` with no streams is the honest shape of "observe,
// then hang up" — there is nothing here to keep alive between calls.
import Foundation
import IHLog
import Network
import os
import Security

/// What one connectivity probe found — see this file's header for the
/// "observe, then hang up" contract; `classify(_:)` is the pure half
/// `LANTroubleshooterAssessmentTests` table-tests directly.
public enum LANConnectivityProbeOutcome: Sendable, Equatable {
    /// TLS completed — a certificate was observed. Carries its SHA-256 hex
    /// fingerprint (never trusted by this call alone; see `+TOFU.swift`'s
    /// contract for the ONLY place a fingerprint like this gets acted on).
    case reachable(fingerprintHex: String)
    /// TCP actively refused — host up, nothing listening on that port.
    case refused
    /// No answer inside the injected timeout — down, blocked, or ISOLATED.
    case timedOut
    /// TCP connected but the TLS handshake itself failed — whatever
    /// answered isn't an iHymns listener.
    case tlsFailed
    /// The hostname didn't resolve.
    case dnsFailed
    /// A parse-level reject — never reaches the network at all.
    case invalidAddress

    /// PURE `NWError` → `Outcome` mapping — table-tested with constructed
    /// `.posix(.ECONNREFUSED)`/`.posix(.ETIMEDOUT)`/`.dns(...)` values;
    /// anything unrecognised degrades to `.timedOut` (the honest "couldn't
    /// reach it" bucket), never a crash.
    public static func classify(_ error: NWError) -> LANConnectivityProbeOutcome {
        switch error {
        case .posix(let code):
            switch code {
            case .ECONNREFUSED:
                return .refused
            case .ETIMEDOUT:
                return .timedOut
            default:
                return .timedOut
            }
        case .dns:
            return .dnsFailed
        case .tls:
            return .tlsFailed
        @unknown default:
            return .timedOut
        }
    }
}

/// A single bounded reachability check — see this file's header.
public enum LANConnectivityProbe {
    /// Connects, observes whatever certificate (if any) is presented,
    /// cancels immediately, and returns the outcome. Sends no application
    /// byte, speaks no IHRP, never calls `hello`, never pairs.
    ///
    /// ELI5: "Knock on this address's door and see if anyone answers — then
    /// leave right away, without saying anything."
    public static func probe(host: String, port: UInt16, timeout: Duration = .seconds(4)) async -> LANConnectivityProbeOutcome {
        guard let nwPort = NWEndpoint.Port(rawValue: port) else { return .invalidAddress }
        let endpoint = NWEndpoint.hostPort(host: .init(host), port: nwPort)
        let queue = DispatchQueue(label: "app.ihymns.lanremote.troubleshoot.probe")

        // Accept-any-cert-OBSERVE, exactly like the TOFU connect's verify
        // block (`RemoteSessionActor+TOFU.swift`) — this is a diagnostic,
        // never a trust decision, so a mismatched/self-signed certificate
        // is exactly what `.reachable` is supposed to report, not reject.
        let observedFingerprint = OSAllocatedUnfairLock<String?>(initialState: nil)
        let tlsOptions = NWProtocolTLS.Options()
        sec_protocol_options_set_min_tls_protocol_version(tlsOptions.securityProtocolOptions, .TLSv13)
        sec_protocol_options_set_verify_block(tlsOptions.securityProtocolOptions, { _, trustRef, complete in
            let presented = RemoteSessionActor.certificateFingerprint(from: trustRef)
            observedFingerprint.withLock { $0 = presented }
            complete(presented != nil)
        }, queue)

        let parameters = NWParameters(tls: tlsOptions, tcp: .init())
        let connection = NWConnection(to: endpoint, using: parameters)

        // "Resolve exactly once" guard — the state-update handler (fires on
        // `connection`'s own queue) and the injected-timeout `Task` both
        // race to call `resolveOnce`; whichever gets there first wins, the
        // other is a silent no-op (never a double-resume of the same
        // continuation).
        let hasResolved = OSAllocatedUnfairLock<Bool>(initialState: false)

        return await withCheckedContinuation { (continuation: CheckedContinuation<LANConnectivityProbeOutcome, Never>) in
            @Sendable
            func resolveOnce(_ outcome: LANConnectivityProbeOutcome) {
                let isFirst = hasResolved.withLock { resolved -> Bool in
                    guard !resolved else { return false }
                    resolved = true
                    return true
                }
                guard isFirst else { return }
                connection.cancel()
                IHLog.remote.notice("lanremote.troubleshoot probe outcome=\(outcome.logCaseName, privacy: .public)")
                continuation.resume(returning: outcome)
            }

            connection.stateUpdateHandler = { state in
                switch state {
                case .ready:
                    let fingerprint = observedFingerprint.withLock { $0 }
                    resolveOnce(fingerprint.map { .reachable(fingerprintHex: $0) } ?? .timedOut)
                case .failed(let error):
                    resolveOnce(.classify(error))
                default:
                    break
                }
            }
            connection.start(queue: queue)

            // `NWConnection` has no built-in connect timeout — race the
            // state handler against an injected sleep so loopback tests run
            // in milliseconds instead of the production default.
            Task {
                try? await Task.sleep(for: timeout)
                resolveOnce(.timedOut)
            }
        }
    }
}

private extension LANConnectivityProbeOutcome {
    /// The exact case-name token the troubleshooter's log line carries —
    /// never the fingerprint or the host (this module's instrumentation
    /// contract: hosts `.private`, a fingerprint `.public` ONLY when it's
    /// the thing a human is about to compare on-screen, never bundled into
    /// a bare diagnostic line like this one).
    var logCaseName: String {
        switch self {
        case .reachable: "reachable"
        case .refused: "refused"
        case .timedOut: "timedOut"
        case .tlsFailed: "tlsFailed"
        case .dnsFailed: "dnsFailed"
        case .invalidAddress: "invalidAddress"
        }
    }
}
