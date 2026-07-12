// RemotePairingFlowStateTests.swift
// IHLiveTests
//
// ELI5: Checks the remote's OWN half of the pairing dance — that it prompts
// for a code (or skips straight to sending one, if we already scanned it),
// that a wrong code lets the person try again, that success/failure end the
// flow cleanly, and that a stray/duplicate event never causes a crash.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.1 — BINDING).
// Pure/always-on — no clock, no actor, no network. The GOLDEN-VECTOR
// cross-check reuses PR-6's frozen (code, fingerprint, nonce) → proof triple
// (`LANRemotePairingProofTests.swift`'s `goldenVector()` test) to prove this
// reducer's `.sendProof` effect carries the EXACT SAME proof the TV's own
// frozen construction expects — both sides call the identical
// `LANRemotePairingProof.compute`, so if this ever drifts it means the
// reducer stopped calling the real function correctly, not that the crypto
// itself changed.
import Foundation
import Testing
@testable import IHLive

@Suite("RemotePairingFlowState")
struct RemotePairingFlowStateTests {

    private func makeState(knownCode: String? = nil, deviceName: String? = "Test Phone") -> RemotePairingFlowState {
        RemotePairingFlowState(context: .init(
            expectedFingerprint: "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789",
            knownCode: knownCode,
            deviceName: deviceName
        ))
    }

    // MARK: - Path A: a scanned ceremony QR already carries the code

    @Test("Path A: challengeReceived with a knownCode sends a proof immediately, no prompt")
    func pathASendsProofImmediately() {
        var state = makeState(knownCode: "042317")
        let effect = state.handle(.challengeReceived(nonce: "0123456789abcdef0123456789abcde0"))

        guard case .sendProof(let proof, let deviceName) = effect else {
            Issue.record("expected .sendProof, got \(effect)")
            return
        }
        #expect(deviceName == "Test Phone")
        #expect(proof == LANRemotePairingProof.compute(
            code: "042317", fingerprintHex: state.context.expectedFingerprint, nonceHex: "0123456789abcdef0123456789abcde0"
        ))
        guard case .proofSent(let nonce, let failedAttempts) = state.phase else {
            Issue.record("expected .proofSent, got \(state.phase)")
            return
        }
        #expect(nonce == "0123456789abcdef0123456789abcde0")
        #expect(failedAttempts == 0)
    }

    @Test("GOLDEN VECTOR cross-check — the reducer's proof equals PR-6's frozen construction verbatim")
    func goldenVectorCrossCheck() {
        let fingerprintHex = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789"
        let nonceHex = "0123456789abcdef0123456789abcde0"
        let code = "042317"
        let expectedProofHex = "8b88b703f398bab753db30059cfde2c10d61b3a18d2cc9640c312f6a89b08847"

        var state = RemotePairingFlowState(context: .init(expectedFingerprint: fingerprintHex, knownCode: code))
        let effect = state.handle(.challengeReceived(nonce: nonceHex))

        guard case .sendProof(let proof, _) = effect else {
            Issue.record("expected .sendProof, got \(effect)")
            return
        }
        #expect(proof == expectedProofHex)
    }

    // MARK: - Path B: no known code — prompt, then send on codeEntered

    @Test("Path B: challengeReceived with no knownCode prompts for a code")
    func pathBPromptsForCode() {
        var state = makeState(knownCode: nil)
        let effect = state.handle(.challengeReceived(nonce: "aa"))
        #expect(effect == .promptForCode(failedAttempts: 0))
        #expect(state.phase == .awaitingCode(nonce: "aa", failedAttempts: 0))
    }

    @Test("Path B: codeEntered sends the computed proof")
    func pathBCodeEnteredSendsProof() {
        var state = makeState(knownCode: nil)
        _ = state.handle(.challengeReceived(nonce: "aa"))
        let effect = state.handle(.codeEntered("111222"))

        guard case .sendProof(let proof, _) = effect else {
            Issue.record("expected .sendProof, got \(effect)")
            return
        }
        #expect(proof == LANRemotePairingProof.compute(code: "111222", fingerprintHex: state.context.expectedFingerprint, nonceHex: "aa"))
        #expect(state.phase == .proofSent(nonce: "aa", failedAttempts: 0))
    }

    @Test("pairingRejected re-prompts with an incremented failedAttempts, retaining the nonce")
    func pairingRejectedRePrompts() {
        var state = makeState(knownCode: nil)
        _ = state.handle(.challengeReceived(nonce: "aa"))
        _ = state.handle(.codeEntered("000000"))
        let effect = state.handle(.pairingRejected)

        #expect(effect == .promptForCode(failedAttempts: 1))
        #expect(state.phase == .awaitingCode(nonce: "aa", failedAttempts: 1))

        // A second reject/re-enter cycle keeps counting.
        _ = state.handle(.codeEntered("111111"))
        let secondEffect = state.handle(.pairingRejected)
        #expect(secondEffect == .promptForCode(failedAttempts: 2))

        // A third.
        _ = state.handle(.codeEntered("222222"))
        let thirdEffect = state.handle(.pairingRejected)
        #expect(thirdEffect == .promptForCode(failedAttempts: 3))
    }

    // MARK: - Expired-scanned-code degrade (Path A → Path B fallback)

    @Test("A knownCode's proof getting rejected degrades to the code sheet — the stale code is never re-sent")
    func expiredScannedCodeDegradesToPrompt() {
        var state = makeState(knownCode: "042317")
        _ = state.handle(.challengeReceived(nonce: "aa"))
        let effect = state.handle(.pairingRejected)

        #expect(effect == .promptForCode(failedAttempts: 1))
        #expect(state.phase == .awaitingCode(nonce: "aa", failedAttempts: 1))

        // The NEXT proof, from a freshly typed code, must be computed from
        // THAT code — never a silently-retried `knownCode`.
        let nextEffect = state.handle(.codeEntered("999999"))
        guard case .sendProof(let proof, _) = nextEffect else {
            Issue.record("expected .sendProof, got \(nextEffect)")
            return
        }
        #expect(proof == LANRemotePairingProof.compute(code: "999999", fingerprintHex: state.context.expectedFingerprint, nonceHex: "aa"))
    }

    // MARK: - Success / transport-closed / out-of-phase events

    @Test("pairSuccess reports paired with the token")
    func pairSuccessReportsPaired() {
        var state = makeState(knownCode: "042317")
        _ = state.handle(.challengeReceived(nonce: "aa"))
        let effect = state.handle(.pairSuccess(token: "deadbeef"))

        #expect(effect == .reportPaired(token: "deadbeef"))
        #expect(state.phase == .paired(token: "deadbeef"))
    }

    @Test("transportClosed from every non-terminal phase reports connectionTornDown")
    func transportClosedFromEveryNonTerminalPhase() {
        // From .awaitingChallenge.
        var awaiting = makeState(knownCode: nil)
        #expect(awaiting.handle(.transportClosed) == .reportFailed(.connectionTornDown))
        #expect(awaiting.phase == .failed(.connectionTornDown))

        // From .awaitingCode.
        var awaitingCode = makeState(knownCode: nil)
        _ = awaitingCode.handle(.challengeReceived(nonce: "aa"))
        #expect(awaitingCode.handle(.transportClosed) == .reportFailed(.connectionTornDown))

        // From .proofSent.
        var proofSent = makeState(knownCode: "042317")
        _ = proofSent.handle(.challengeReceived(nonce: "aa"))
        #expect(proofSent.handle(.transportClosed) == .reportFailed(.connectionTornDown))
    }

    @Test("Out-of-phase events are ignored (.none), never a crash")
    func outOfPhaseEventsAreIgnored() {
        var state = makeState(knownCode: "042317")
        _ = state.handle(.challengeReceived(nonce: "aa"))
        _ = state.handle(.pairSuccess(token: "deadbeef"))

        // A duplicate pairingRejected/pairSuccess after the flow already
        // resolved must be a no-op, not a crash/assert.
        #expect(state.handle(.pairingRejected) == .none)
        #expect(state.handle(.codeEntered("000000")) == .none)
        #expect(state.phase == .paired(token: "deadbeef"))

        // challengeReceived while already awaiting a proof is also a no-op.
        var proofSent = makeState(knownCode: "042317")
        _ = proofSent.handle(.challengeReceived(nonce: "aa"))
        #expect(proofSent.handle(.challengeReceived(nonce: "bb")) == .none)
    }

    @Test("Phase never carries a live code — awaitingCode/proofSent only ever hold nonce + count")
    func phaseNeverStoresALiveCode() {
        // Structural: the ONLY way to prove "never stores a code" from the
        // outside is that these two cases' associated values are exactly
        // (nonce, failedAttempts) — verified here by construction/equality
        // against literals that contain no code at all.
        let awaitingCode = RemotePairingFlowState.Phase.awaitingCode(nonce: "aa", failedAttempts: 2)
        let proofSent = RemotePairingFlowState.Phase.proofSent(nonce: "aa", failedAttempts: 2)
        #expect(awaitingCode == .awaitingCode(nonce: "aa", failedAttempts: 2))
        #expect(proofSent == .proofSent(nonce: "aa", failedAttempts: 2))
    }
}
