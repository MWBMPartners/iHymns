// RemotePairingEntryResolver.swift
// IHLive/LANRemote
//
// ELI5: The one place that turns "I scanned this QR" / "I tapped this row in
// my list" into "here's exactly how to connect" — which address to dial,
// which fingerprint to pin, whether we already have a saved token or need to
// run the code ceremony. It also builds the list of rows the UI shows:
// TVs we already trust first, then nearby TVs we've never paired with (which
// can only ever say "scan my QR to pair," never be tapped to connect).
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §4 — the entry-path
// matrix, BINDING, "resolve it EXACTLY this way"). This is a PURE, static
// enum — no actor, no I/O, no network call — so `RemotePairingEntryResolverTests`
// can table-drive every row of the spec's matrix without a live listener.
//
// **The invariant behind the whole file:** `expectedFingerprint` must be in
// hand BEFORE any connection is attempted, and it comes from exactly two
// sources this resolver reads — a scanned/pasted `LANRemotePairingPayload`,
// or an already-saved `PairedTVRecord`. Bonjour discovery (`discovered:`)
// NEVER supplies trust here — a `LANRemoteDiscoveredService` is name +
// endpoint only (`LANRemoteDiscovery.swift`'s header) — name-matching it to
// a saved/scanned fingerprint is ROUTING ONLY (picking which endpoint to
// dial); an impostor advertising a trusted TV's name still fails the SAVED
// fingerprint's TLS pin at handshake (`RemoteSessionActor+Connection.swift`).
// A nearby TV that ISN'T already paired can therefore never be resolved to a
// `.connect(...)` target at all — `listRows(saved:discovered:)` marks it
// `.nearbyUnpaired`, and the UI's only affordance for that row is opening the
// scan/paste sheet (spec §4 row C′: "wiring ANY connect here... is the PR-8
// boundary violation").
//
// `RemoteConnectTarget` (this file) is the concrete type
// `RemoteControlSession.Target` (`RemoteControlSession.swift`, #1422 commit 2)
// aliases to — defined HERE, not nested inside the session actor, because
// this resolver is its ONLY producer (spec §3.4: "produced by
// `RemotePairingEntryResolver`... never assembled ad-hoc in a view") and
// keeping the concrete shape beside the code that builds it lets this file
// compile — and be fully tested — before `RemoteControlSession` exists at
// all (this PR's commit 1 has no dependency on commit 2's actor).
import Foundation
import Network

/// Everything needed to (re)connect to ONE TV. `RemoteControlSession.Target`
/// is a `typealias` to this exact type — see this file's header for why the
/// concrete struct lives here.
///
/// ELI5: "Here's the TV, here's how to reach it, here's the fingerprint to
/// pin, and here's whether we can skip straight to the fast reconnect."
public struct RemoteConnectTarget: Sendable {
    /// Cosmetic — shown while connecting/reconnecting.
    public let tvName: String

    /// The primary endpoint to dial — either a discovered Bonjour service or
    /// an explicit `.hostPort` built from a scanned/pasted payload.
    public let endpoint: NWEndpoint

    /// A saved last-good `host:port` to fall back to if `endpoint` (when it's
    /// a Bonjour service) can't be reached — `RemoteControlSession+Link.swift`'s
    /// reconnect ladder alternates between the two.
    public let fallbackEndpoint: NWEndpoint?

    /// ALWAYS present — no TOFU (PR-8's boundary, not this PR's).
    public let expectedFingerprint: String

    /// `nil` ⇒ the ceremony must run; non-nil ⇒ the fast `hello(token:)`
    /// reconnect path (and this connection is eligible for the auto-reconnect
    /// ladder on a later drop — `RemoteControlSession+Link.swift`).
    public let token: String?

    /// Path A only — the code a scanned ceremony QR already carried, so the
    /// challenge can be auto-answered without prompting.
    public let knownCode: String?

    public init(
        tvName: String,
        endpoint: NWEndpoint,
        fallbackEndpoint: NWEndpoint? = nil,
        expectedFingerprint: String,
        token: String? = nil,
        knownCode: String? = nil
    ) {
        self.tvName = tvName
        self.endpoint = endpoint
        self.fallbackEndpoint = fallbackEndpoint
        self.expectedFingerprint = expectedFingerprint
        self.token = token
        self.knownCode = knownCode
    }
}

/// One row in the discovery/paired-TV list (`RemoteControlCoordinator.rows`,
/// #1422 commit 3) — saved TVs (optionally flagged "nearby" when a discovered
/// service matches their name) followed by unpaired nearby TVs, which are
/// informational only (spec §4 row C′).
public struct RemoteTVListRow: Sendable, Equatable, Identifiable {
    public enum Kind: Sendable, Equatable {
        case paired(PairedTVRecord)
        case nearbyUnpaired(LANRemoteDiscoveredService)
    }

    public let kind: Kind

    /// Whether a currently-discovered Bonjour service shares this row's
    /// name — routing information only (this file's header), never a trust
    /// signal. Always `true` for a `.nearbyUnpaired` row (it exists because
    /// it WAS discovered).
    public let isNearby: Bool

    public init(kind: Kind, isNearby: Bool) {
        self.kind = kind
        self.isNearby = isNearby
    }

    public var id: String {
        switch kind {
        case .paired(let record): "paired:\(record.fingerprintHex)"
        case .nearbyUnpaired(let service): "nearby:\(service.name)"
        }
    }

    public var displayName: String {
        switch kind {
        case .paired(let record): record.name
        case .nearbyUnpaired(let service): service.name
        }
    }
}

/// The pure entry-path resolver — see this file's header for the full
/// invariant it enforces.
public enum RemotePairingEntryResolver {

    /// The two things a "connect" action can start from — a scanned/pasted
    /// QR payload (paths A/B/B′), or a tap on an already-saved list row
    /// (path C). A tap on an UNPAIRED nearby row is deliberately NOT an
    /// `Entry` case at all — see this file's header for why.
    public enum Entry: Sendable {
        case payload(LANRemotePairingPayload)
        case savedRow(PairedTVRecord)
    }

    /// Why a `resolve(...)` call could not produce a connectable target.
    public enum UnpairableReason: Sendable, Equatable {
        /// A payload's `host` was `nil` and no discovered service's name
        /// matched `payload.name` — there is genuinely no address to dial
        /// (spec §4 row A/B: "if neither → error card").
        case noRouteToTV
    }

    public enum Resolution: Sendable {
        case connect(RemoteConnectTarget)
        case unpairable(reason: UnpairableReason)
    }

    /// Resolves one entry into a connect target — spec §4's table, exactly.
    ///
    /// ELI5: "I want to connect to this TV — work out everything needed to
    /// actually do that."
    public static func resolve(
        _ entry: Entry, saved: [PairedTVRecord], discovered: [LANRemoteDiscoveredService]
    ) -> Resolution {
        switch entry {
        case .payload(let payload):
            return resolvePayload(payload, saved: saved, discovered: discovered)
        case .savedRow(let record):
            return resolveSavedRow(record, discovered: discovered)
        }
    }

    /// The list model: saved records first (pinned, `isNearby` flagged when
    /// a discovered name matches), sorted by `lastConnectedAt` (falling back
    /// to `pairedAt`) descending; then unpaired nearby services, sorted by
    /// name.
    ///
    /// ELI5: "Build the list the browsing screen shows."
    public static func listRows(saved: [PairedTVRecord], discovered: [LANRemoteDiscoveredService]) -> [RemoteTVListRow] {
        let discoveredNames = Set(discovered.map(\.name))
        let savedRows = saved
            .sorted { (savedSortKey($0)) > (savedSortKey($1)) }
            .map { RemoteTVListRow(kind: .paired($0), isNearby: discoveredNames.contains($0.name)) }

        let savedNames = Set(saved.map(\.name))
        let nearbyRows = discovered
            .filter { !savedNames.contains($0.name) }
            .sorted { $0.name.localizedStandardCompare($1.name) == .orderedAscending }
            .map { RemoteTVListRow(kind: .nearbyUnpaired($0), isNearby: true) }

        return savedRows + nearbyRows
    }

    private static func savedSortKey(_ record: PairedTVRecord) -> Date {
        record.lastConnectedAt ?? record.pairedAt
    }

    // MARK: - Path A/B/B′ — a scanned/pasted payload

    private static func resolvePayload(
        _ payload: LANRemotePairingPayload, saved: [PairedTVRecord], discovered: [LANRemoteDiscoveredService]
    ) -> Resolution {
        guard let endpoint = endpointFromPayload(payload, discovered: discovered) else {
            return .unpairable(reason: .noRouteToTV)
        }

        let matchingSaved = saved.first { $0.fingerprintHex == payload.fingerprintHex }
        let fallback = matchingSaved.flatMap(fallbackEndpoint)

        // A ceremony payload (`code != nil`) always means "the user intends
        // to (re)pair" — the ceremony runs even for an already-known
        // fingerprint (a re-pair overwrites the saved record on success,
        // §4's extra resolver rule); `token` stays `nil` so the flow never
        // silently short-circuits it. A STANDING payload (`code == nil`)
        // for an ALREADY-saved fingerprint is where we already trust this
        // TV — connect with the saved token instead of making the user type
        // a code pointlessly; only a `.pairChallenge` arriving instead of
        // `.capabilities` (a revoked/stale token) drops it into the code
        // sheet, which the §1.1 wire contract gives for free.
        let token = (payload.code == nil) ? matchingSaved?.token : nil

        return .connect(RemoteConnectTarget(
            tvName: payload.name,
            endpoint: endpoint,
            fallbackEndpoint: fallback,
            expectedFingerprint: payload.fingerprintHex,
            token: token,
            knownCode: payload.code
        ))
    }

    /// A payload's own `host`/`port` win when present; otherwise a
    /// discovered service whose Bonjour name matches `payload.name` supplies
    /// the endpoint (routing only, never trust — this file's header);
    /// `nil` when neither is available.
    private static func endpointFromPayload(
        _ payload: LANRemotePairingPayload, discovered: [LANRemoteDiscoveredService]
    ) -> NWEndpoint? {
        if let host = payload.host, let port = payload.port, let nwPort = NWEndpoint.Port(rawValue: port) {
            return .hostPort(host: .init(host), port: nwPort)
        }
        return discovered.first { $0.name == payload.name }?.endpoint
    }

    // MARK: - Path C — a tap on an already-saved list row

    private static func resolveSavedRow(_ record: PairedTVRecord, discovered: [LANRemoteDiscoveredService]) -> Resolution {
        if let discoveredMatch = discovered.first(where: { $0.name == record.name }) {
            return .connect(RemoteConnectTarget(
                tvName: record.name,
                endpoint: discoveredMatch.endpoint,
                fallbackEndpoint: fallbackEndpoint(for: record),
                expectedFingerprint: record.fingerprintHex,
                token: record.token,
                knownCode: nil
            ))
        }
        guard let addressEndpoint = fallbackEndpoint(for: record) else {
            // Never connected before (no saved address) AND not currently
            // discovered — genuinely nothing to dial.
            return .unpairable(reason: .noRouteToTV)
        }
        return .connect(RemoteConnectTarget(
            tvName: record.name,
            endpoint: addressEndpoint,
            fallbackEndpoint: nil,
            expectedFingerprint: record.fingerprintHex,
            token: record.token,
            knownCode: nil
        ))
    }

    private static func fallbackEndpoint(for record: PairedTVRecord) -> NWEndpoint? {
        guard let address = record.lastAddress, let port = NWEndpoint.Port(rawValue: address.port) else { return nil }
        return .hostPort(host: .init(address.host), port: port)
    }
}
