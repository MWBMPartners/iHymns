// TVListenerActor+Connections.swift
// IHLive/LANRemote
//
// ELI5: The plumbing for "accept a new connection, read whatever bytes
// arrive off it, and send bytes back" — kept separate from
// `TVListenerActor.swift`'s configuration/lifecycle and
// `TVListenerActor+Messages.swift`'s "what does this message MEAN" logic.
//
// DETAILED: #1420. Split out of `TVListenerActor.swift` purely to stay
// under `Scripts/loc-budget.sh`'s per-file line budget — a same-target
// file split, the exact pattern `IHAPI/APIClient+Networking.swift`'s own
// header comment documents for the identical reason.
//
// **The one rule every method here obeys:** an `NWConnection`'s callback
// closures (`stateUpdateHandler`, `receive(...)`'s completion, `send(...)`'s
// completion) run on `networkQueue` — NOT actor-isolated. Every closure in
// this file captures ONLY `self` (an actor reference, inherently `Sendable`)
// and an immutable `UUID`, then re-enters the actor via
// `Task { await self.<method>(...) }` before touching ANY actor-isolated
// state (`connections`, `canonicalState`, etc.) — never the `NWConnection`
// or `TVRemoteConnectionState` class instance itself, which must always be
// re-looked-up from `connections[id]` INSIDE actor-isolated code, never
// captured directly into a queue-side closure.
import Foundation
import IHLog
import Network

/// The three-state pairing state machine strategy §2.4.3 (and the task
/// brief) describes: `unpaired` (only the pairing sub-protocol —
/// `hello`/`ping` — is accepted) → `pairing` (awaiting the out-of-band
/// ceremony PR-6 implements) → `paired` (full IHRP control accepted).
enum TVConnectionPairingPhase: Equatable {
    case unpaired
    case pairing
    case paired(token: String)

    var isPaired: Bool {
        if case .paired = self { true } else { false }
    }
}

/// Mutable per-connection state — a plain (non-`Sendable`) class,
/// deliberately: every instance is created, mutated, and destroyed
/// EXCLUSIVELY inside `TVListenerActor`'s isolated methods (see this file's
/// header comment) and never crosses an isolation boundary itself, only the
/// `UUID` key used to look it back up does — so it needs no concurrency
/// annotations of its own.
final class TVRemoteConnectionState {
    let id: UUID
    let connection: NWConnection
    var pairingPhase: TVConnectionPairingPhase = .unpaired
    var decoder = IHRPFrameDecoder()
    var remoteKind: IHRPRemoteKind?

    /// The per-connection nonce minted the instant this connection entered
    /// `.pairing` (#1421, PR-6 spec §3.2) — `.pairConfirm`'s proof binds to
    /// THIS value; `nil` before `.pairing` is entered and cleared again by
    /// `promoteToPaired` once pairing succeeds (`TVListenerActor+Pairing.swift`).
    var pairingNonce: String?

    /// `.pairConfirm` attempts made ON THIS CONNECTION — the per-connection
    /// cap `TVListenerActor+Pairing.swift`'s `handlePairConfirm` enforces
    /// (distinct from `LANRemotePairingCeremonyState.wrongAttempts`, which
    /// is GLOBAL per-code, not per-connection).
    var pairingAttempts: Int = 0

    init(id: UUID, connection: NWConnection) {
        self.id = id
        self.connection = connection
    }
}

extension TVListenerActor {
    /// Accepts (or rejects, if the unpaired cap is already reached) a
    /// freshly-arrived connection from `NWListener.newConnectionHandler`.
    func handleNewConnection(_ connection: NWConnection) async {
        guard unpairedConnectionCount < configuration.maxUnpairedConnections else {
            IHLog.remote.error(
                "lanremote.connection rejected reason=unpaired-cap cap=\(self.configuration.maxUnpairedConnections, privacy: .public)"
            )
            connection.cancel()
            return
        }

        let id = UUID()
        let state = TVRemoteConnectionState(id: id, connection: connection)
        connections[id] = state

        connection.stateUpdateHandler = { [weak self] nwState in
            guard let self else { return }
            Task { await self.onConnectionStateChanged(id: id, state: nwState) }
        }
        connection.start(queue: networkQueue)
        scheduleReceive(id: id)
    }

    func onConnectionStateChanged(id: UUID, state: NWConnection.State) async {
        guard connections[id] != nil else { return }
        switch state {
        case .ready:
            IHLog.remote.notice("lanremote.connection ready")
        case .failed(let error):
            IHLog.remote.notice("lanremote.connection failed error=\(String(describing: error), privacy: .public)")
            teardownConnection(id: id, reason: "failed")
        case .cancelled:
            teardownConnection(id: id, reason: "cancelled")
        default:
            break
        }
    }

    /// Schedules the next `receive` on `id`'s connection — re-armed after
    /// every successful read, forming the connection's receive loop.
    func scheduleReceive(id: UUID) {
        guard let state = connections[id] else { return }
        state.connection.receive(minimumIncompleteLength: 1, maximumLength: 65_536) { [weak self] data, _, isComplete, error in
            guard let self else { return }
            Task { await self.onReceive(id: id, data: data, isComplete: isComplete, error: error) }
        }
    }

    func onReceive(id: UUID, data: Data?, isComplete: Bool, error: NWError?) async {
        guard let state = connections[id] else { return }

        if let error {
            teardownConnection(id: id, reason: "receive error \(error)")
            return
        }

        if let data, !data.isEmpty {
            let frames: [Data]
            do {
                frames = try state.decoder.feed(data)
            } catch IHRPFramerError.frameTooLarge(let declared, let cap) {
                // Task brief: "a frame exceeding the cap is a protocol error
                // that drops the connection (never buffer unboundedly)."
                // `.fault` — this is the one class of malformed input this
                // actor treats as worth Instruments/Console alarm-level
                // attention (a legitimate paired remote should NEVER send
                // an oversize frame; this is either a bug or hostile).
                IHLog.remote.fault(
                    "lanremote.frame oversize declared=\(declared, privacy: .public) cap=\(cap, privacy: .public)"
                )
                teardownConnection(id: id, reason: "frame too large")
                return
            } catch {
                teardownConnection(id: id, reason: "framer error")
                return
            }

            for frameData in frames {
                await handleIncomingFrameData(id: id, data: frameData)
                guard connections[id] != nil else { return } // dropped mid-batch
            }
        }

        if isComplete {
            teardownConnection(id: id, reason: "peer closed")
            return
        }

        if connections[id] != nil {
            scheduleReceive(id: id)
        }
    }

    /// Sends `message` to `id`'s connection, wrapped in a fresh
    /// `IHRPFrame` (this TV's own monotonic `outgoingSeq` + the injected
    /// clock's current time) and TLV-framed.
    func send(id: UUID, message: IHRPMessage) {
        guard let state = connections[id] else { return }
        outgoingSeq += 1
        let frame = IHRPFrame(seq: outgoingSeq, timestamp: configuration.clock.now(), message: message)

        do {
            let payload = try JSONEncoder().encode(frame)
            let framed = try IHRPFramer.encode(payload)
            state.connection.send(content: framed, completion: .contentProcessed { error in
                if let error {
                    IHLog.remote.error("lanremote.send failed error=\(String(describing: error), privacy: .public)")
                }
            })
            IHLog.remote.debug("lanremote.send type=\(message.caseName, privacy: .public)")
        } catch {
            IHLog.remote.error("lanremote.send encode-failed type=\(message.caseName, privacy: .public)")
        }
    }

    /// Closes and forgets a connection.
    func teardownConnection(id: UUID, reason: String) {
        guard let state = connections.removeValue(forKey: id) else { return }
        // #1421 (PR-6 spec §2) — a `.pairing` connection that never
        // confirmed is disappearing; the overlay's "N remotes trying to
        // pair" indicator needs to know, or it would over-count forever.
        if state.pairingPhase == .pairing {
            pairingContinuation.yield(.remoteLeftPairing(connectionId: id))
        }
        state.connection.cancel()
        IHLog.remote.notice("lanremote.connection closed reason=\(reason, privacy: .public)")
    }
}
