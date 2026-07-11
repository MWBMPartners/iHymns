// TVListenerActor+Messages.swift
// IHLive/LANRemote
//
// ELI5: "Now that we have a whole message off the wire, what does it MEAN,
// and is this connection even allowed to say it yet?" — the pairing-state
// confinement and the actual command handling live here.
//
// DETAILED: #1420. Split out of `TVListenerActor.swift` for the same LOC-
// budget reason `TVListenerActor+Connections.swift` documents.
import Foundation
import IHLog
import IHModels

extension TVListenerActor {
    /// Decodes one complete frame payload (already de-TLV'd by
    /// `TVListenerActor+Connections.swift`'s receive loop) and dispatches
    /// it — malformed JSON is ALSO a protocol error worth dropping the
    /// connection over, the same posture as an oversize frame.
    func handleIncomingFrameData(id: UUID, data: Data) async {
        guard let state = connections[id] else { return }

        let frame: IHRPFrame
        do {
            frame = try JSONDecoder().decode(IHRPFrame.self, from: data)
        } catch {
            IHLog.remote.error("lanremote.recv malformed-frame")
            teardownConnection(id: id, reason: "malformed frame")
            return
        }

        IHLog.remote.debug("lanremote.recv type=\(frame.message.caseName, privacy: .public)")

        switch state.pairingPhase {
        case .unpaired, .pairing:
            await handleUnauthenticated(id: id, state: state, frame: frame)
        case .paired:
            await handlePaired(id: id, state: state, frame: frame)
        }
    }

    /// The `.unpaired`/`.pairing` confinement: task brief — "unpaired
    /// (only the pairing sub-protocol is accepted)". For THIS PR, "the
    /// pairing sub-protocol" is `hello` (which either fast-paths a
    /// returning remote straight to `.paired` via `pairingAuthority`, or
    /// parks the connection in `.pairing` awaiting PR-6's out-of-band
    /// ceremony via `completePairing(connectionId:token:)`) and `ping`
    /// (a harmless keepalive, safe to answer regardless of trust). Any
    /// OTHER message — a genuine control intent, or a TV-only response
    /// type a remote should never send — is a confinement violation and
    /// drops the connection.
    func handleUnauthenticated(id: UUID, state: TVRemoteConnectionState, frame: IHRPFrame) async {
        switch frame.message {
        case .ping:
            send(id: id, message: .pong)

        case .hello(let token, let kind):
            state.remoteKind = kind
            send(id: id, message: .ack(ackSeq: frame.seq))

            if let token, await configuration.pairingAuthority.isPairedToken(token) {
                state.pairingPhase = .paired(token: token)
                // Pairing STEP TRANSITION — `.notice`, NEVER the token
                // itself (task brief's instrumentation contract, this
                // module's `LANRemotePairingAuthority.swift` header
                // comment).
                IHLog.remote.notice("lanremote.pairing unpaired -> paired (reconnect)")
                send(id: id, message: .capabilities(IHRPCapabilities(currentState: canonicalState)))
            } else {
                state.pairingPhase = .pairing
                IHLog.remote.notice("lanremote.pairing unpaired -> pairing")
            }

        default:
            IHLog.remote.error(
                "lanremote.pairing violation type=\(frame.message.caseName, privacy: .public) while unconfirmed"
            )
            send(id: id, message: .error(.unauthorized, message: nil))
            teardownConnection(id: id, reason: "unauthorized control while unconfirmed")
        }
    }

    /// Full IHRP handling once a connection is `.paired`.
    func handlePaired(id: UUID, state: TVRemoteConnectionState, frame: IHRPFrame) async {
        switch frame.message {
        case .ping:
            send(id: id, message: .pong)

        case .hello, .state, .ack, .error, .pong, .capabilities:
            // A duplicate `hello`, or a response-only case a well-behaved
            // remote should never SEND — reject without dropping an
            // already-trusted connection over what is most likely a bug,
            // not an attack.
            send(id: id, message: .error(.malformedRequest, message: "unexpected on a paired connection"))

        default:
            // Every remaining case is a genuine control intent
            // (`prepare`/`selectSong`/`next…`/`prev…`/`jumpLine`/
            // `setDisplayState`/`scroll`/`setAppearance`/`endControl`).
            guard applyLastWriterWins(frame) else {
                IHLog.remote.notice("lanremote.control stale (lost last-writer-wins)")
                send(id: id, message: .ack(ackSeq: frame.seq))
                return
            }
            send(id: id, message: .ack(ackSeq: frame.seq))
            continuation.yield(ControlEvent(connectionId: id, frame: frame))
        }
    }

    /// Strategy §2.4.4's "commands by seq+timestamp" conflict rule — see
    /// `IHRPFrame.isMoreRecent(than:)`'s own doc comment for the full
    /// reasoning. Returns `false` (and leaves `lastAcceptedControlFrame`
    /// untouched) for a frame that arrived out of order relative to one
    /// already accepted.
    func applyLastWriterWins(_ frame: IHRPFrame) -> Bool {
        if let last = lastAcceptedControlFrame, !frame.isMoreRecent(than: last) {
            return false
        }
        lastAcceptedControlFrame = frame
        return true
    }

    /// Sends `message` to every currently-`.paired` connection — the
    /// "broadcast to all connected" half of strategy §2.4.4's TV→remotes
    /// response list.
    func broadcast(_ message: IHRPMessage) {
        for (id, state) in connections where state.pairingPhase.isPaired {
            send(id: id, message: message)
        }
    }

    /// Called by PR-6's pairing-ceremony UI once a `.pairing` connection
    /// completes out-of-band verification (the 6-digit code/QR flow —
    /// entirely PR-6's implementation; this actor only needs the RESULT).
    ///
    /// ELI5: "The person standing at the TV just confirmed this remote —
    /// let it in."
    ///
    /// - Returns: `false` if `connectionId` isn't currently `.pairing`
    ///   (already paired, already gone, or never entered pairing) — the
    ///   caller should treat that as "nothing to confirm," not an error.
    @discardableResult
    public func completePairing(connectionId: UUID, token: String) async -> Bool {
        guard let state = connections[connectionId], state.pairingPhase == .pairing else { return false }
        await configuration.pairingAuthority.registerPairedToken(token)
        state.pairingPhase = .paired(token: token)
        IHLog.remote.notice("lanremote.pairing pairing -> paired")
        send(id: connectionId, message: .capabilities(IHRPCapabilities(currentState: canonicalState)))
        return true
    }

    /// Called by the display-driving layer (PR-5/PR-6) once it has
    /// resolved a `controlEvents` intent into an actual new canonical
    /// state — bumps `revision` and broadcasts `.state` to every paired
    /// remote. This file's header comment explains why resolution itself
    /// happens ABOVE this actor.
    ///
    /// ELI5: "Here's what's on screen now — tell every connected remote."
    public func updateState(songId: SongID?, componentIndex: Int?, lineIndex: Int?, displayState: IHRPDisplayState) async {
        let signpostID = IHLog.signposter.makeSignpostID()
        let interval = IHLog.signposter.beginInterval("remote.apply", id: signpostID)
        defer { IHLog.signposter.endInterval("remote.apply", interval) }

        canonicalState = IHRPState(
            songId: songId,
            componentIndex: componentIndex,
            lineIndex: lineIndex,
            displayState: displayState,
            revision: canonicalState.revision + 1
        )
        broadcast(.state(canonicalState))
    }
}
