// RemoteSessionActor+Connection.swift
// IHLive/LANRemote
//
// ELI5: The "actually connect to one specific TV, prove it's really that
// TV, and then talk to it" half of the remote-device actor — split from
// `RemoteSessionActor.swift`'s discovery half for the same LOC-budget
// reason `TVListenerActor`'s files are split.
//
// DETAILED: #1420. **Pin-before-trust (task brief; strategy §2.4.3) is the
// WHOLE security model here** — this connection NEVER asks the OS to
// validate the TV's self-signed certificate against the system trust store
// (that would fail outright for a self-signed leaf anyway, per
// `LANRemoteIdentityDER.swift`'s header comment). Instead,
// `sec_protocol_options_set_verify_block` below does the ONE check that
// matters: does the presented certificate's SHA-256 fingerprint match the
// value shown out-of-band (QR/manual code, PR-6)? A match completes the
// handshake; ANY mismatch — including "no expected fingerprint was ever
// set" — rejects it outright, logged `.fault` (task brief: "pin mismatch =
// `.fault`" — the single highest-signal LAN-remote log line this whole
// module writes, on par with "local-network-permission-denied" on the
// discovery side).
import Foundation
import IHLog
import Network
import os
import Security

extension RemoteSessionActor {
    /// Connects to `endpoint`, pinning the TV's certificate against
    /// `expectedFingerprint` and sending `hello` once the transport is up.
    /// Resolves once TLS negotiation (and therefore the pin check) has
    /// succeeded; throws if it fails for any reason (transport error OR a
    /// rejected pin — see `RemoteSessionError.transport`'s doc comment for
    /// why the two aren't distinguished at this layer).
    ///
    /// ELI5: "Connect to this specific TV, and don't trust it unless its
    /// fingerprint matches the one I was shown."
    ///
    /// - Parameters:
    ///   - endpoint: Either a Bonjour `.service(...)` result from
    ///     `startDiscovery()`, or an explicit `.hostPort(host:port:)` — the
    ///     task brief's required path for both the CI test suite and
    ///     strategy §2.4.5's "manual connect-by-address" v1 rung.
    ///   - expectedFingerprint: The pinned SHA-256 hex fingerprint, from
    ///     `LANRemoteIdentity.fingerprint` shown out-of-band.
    ///   - token: A previously-paired remote's saved token (fast-path
    ///     reconnect), or `nil` for a brand-new pairing attempt.
    public func connect(to endpoint: NWEndpoint, expectedFingerprint: String, token: String?) async throws {
        disconnect()
        self.expectedFingerprint = expectedFingerprint
        self.pairingToken = token
        setPhase(.connecting)

        let signpostID = IHLog.signposter.makeSignpostID()
        let interval = IHLog.signposter.beginInterval("lan.connect", id: signpostID)

        let tlsOptions = NWProtocolTLS.Options()
        sec_protocol_options_set_min_tls_protocol_version(tlsOptions.securityProtocolOptions, .TLSv13)
        sec_protocol_options_set_verify_block(tlsOptions.securityProtocolOptions, { [expectedFingerprint] _, trustRef, complete in
            let presented = Self.certificateFingerprint(from: trustRef)
            let matches = presented != nil && presented == expectedFingerprint
            if matches {
                IHLog.remote.notice(
                    "lanremote.pin ok fingerprint=\(expectedFingerprint, privacy: .public)"
                )
            } else {
                // The single highest-signal line this file writes — see
                // this file's header comment.
                IHLog.remote.fault(
                    "lanremote.pin mismatch expected=\(expectedFingerprint, privacy: .public) presented=\(presented ?? "none", privacy: .public)"
                )
            }
            complete(matches)
        }, networkQueue)

        let parameters = NWParameters(tls: tlsOptions, tcp: .init())
        let conn = NWConnection(to: endpoint, using: parameters)
        connection = conn

        conn.stateUpdateHandler = { [weak self] state in
            guard let self else { return }
            Task { await self.onConnectionStateChanged(state) }
        }

        do {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
                pendingConnectContinuation = continuation
                conn.start(queue: networkQueue)
            }
            IHLog.signposter.endInterval("lan.connect", interval, "ok")
            scheduleReceive()
            await sendHello()
        } catch {
            IHLog.signposter.endInterval("lan.connect", interval, "failed")
            throw error
        }
    }

    /// Sends a message. Fails fast with `.notConnected` if the transport
    /// isn't up — this actor never silently queues a send for later (a
    /// "reconnect and replay pending commands" policy is PR-7's UI-layer
    /// concern, not this transport actor's).
    public func send(_ message: IHRPMessage) throws {
        guard let connection, phase.isConnected else { throw RemoteSessionError.notConnected }
        outgoingSeq += 1
        let frame = IHRPFrame(seq: outgoingSeq, timestamp: configuration.clock.now(), message: message)

        let payload: Data
        let framed: Data
        do {
            payload = try JSONEncoder().encode(frame)
            framed = try IHRPFramer.encode(payload)
        } catch {
            throw RemoteSessionError.encodingFailed
        }

        connection.send(content: framed, completion: .contentProcessed { error in
            if let error {
                IHLog.remote.error("lanremote.send failed error=\(String(describing: error), privacy: .public)")
            }
        })
        IHLog.remote.debug("lanremote.send type=\(message.caseName, privacy: .public)")
    }

    /// Closes the current connection (if any) and returns to `.idle`.
    ///
    /// ELI5: "Hang up."
    public func disconnect() {
        connection?.cancel()
        connection = nil
        decoder = IHRPFrameDecoder()
        if let pendingConnectContinuation {
            pendingConnectContinuation.resume(throwing: RemoteSessionError.notConnected)
            self.pendingConnectContinuation = nil
        }
        setPhase(.idle)
    }

    func onConnectionStateChanged(_ state: NWConnection.State) async {
        switch state {
        case .ready:
            setPhase(.awaitingPairing)
            pendingConnectContinuation?.resume()
            pendingConnectContinuation = nil
        case .failed(let error):
            let sessionError = RemoteSessionError.transport(String(describing: error))
            setPhase(.failed(sessionError))
            pendingConnectContinuation?.resume(throwing: sessionError)
            pendingConnectContinuation = nil
        case .cancelled:
            break
        default:
            break
        }
    }

    func sendHello() async {
        try? send(.hello(token: pairingToken, kind: configuration.kind))
    }

    func scheduleReceive() {
        guard let connection else { return }
        connection.receive(minimumIncompleteLength: 1, maximumLength: 65_536) { [weak self] data, _, isComplete, error in
            guard let self else { return }
            Task { await self.onReceive(data: data, isComplete: isComplete, error: error) }
        }
    }

    func onReceive(data: Data?, isComplete: Bool, error: NWError?) async {
        guard connection != nil else { return }

        if let error {
            disconnect()
            IHLog.remote.notice("lanremote.connection receive-error \(String(describing: error), privacy: .public)")
            return
        }

        if let data, !data.isEmpty {
            let frames: [Data]
            do {
                frames = try decoder.feed(data)
            } catch IHRPFramerError.frameTooLarge(let declared, let cap) {
                IHLog.remote.fault("lanremote.frame oversize declared=\(declared, privacy: .public) cap=\(cap, privacy: .public)")
                disconnect()
                return
            } catch {
                disconnect()
                return
            }

            for frameData in frames {
                await handleIncomingFrameData(frameData)
                guard connection != nil else { return }
            }
        }

        if isComplete {
            disconnect()
            return
        }

        if connection != nil {
            scheduleReceive()
        }
    }

    func handleIncomingFrameData(_ data: Data) async {
        let frame: IHRPFrame
        do {
            frame = try JSONDecoder().decode(IHRPFrame.self, from: data)
        } catch {
            IHLog.remote.error("lanremote.recv malformed-frame")
            disconnect()
            return
        }

        IHLog.remote.debug("lanremote.recv type=\(frame.message.caseName, privacy: .public)")
        messageContinuation.yield(frame)

        switch frame.message {
        case .capabilities(let capabilities):
            setPhase(.controlling(capabilities.currentState))
        case .state(let state):
            setPhase(.controlling(state))
        case .error(let code, _):
            if code == .unauthorized {
                disconnect()
            }
        default:
            break
        }
    }

    /// Extracts the presented leaf certificate's SHA-256 hex fingerprint
    /// from a TLS verify block's `sec_trust_t` — `nil` if the peer somehow
    /// presented no certificate at all (also treated as a mismatch by the
    /// caller above).
    static func certificateFingerprint(from trustRef: sec_trust_t) -> String? {
        let trust = sec_trust_copy_ref(trustRef).takeRetainedValue()
        guard let chain = SecTrustCopyCertificateChain(trust) as? [SecCertificate], let leaf = chain.first else {
            return nil
        }
        let der = SecCertificateCopyData(leaf) as Data
        return LANRemoteFingerprint.sha256Hex(der)
    }
}
