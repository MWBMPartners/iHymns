// LANRemoteDiscovery.swift
// IHLive/LANRemote
//
// ELI5: The one shared "name" TVs advertise themselves under on the local
// network, and remotes look for — kept in exactly one place so
// `TVListenerActor` (which advertises it) and `RemoteSessionActor` (which
// browses for it) can never drift apart.
//
// DETAILED: #1420. `.claude/apple-native-strategy.md` §2.4.2: "Bonjour
// `_ihymns-remote._tcp`". This file does NOT add the
// `NSBonjourServices`/`NSLocalNetworkUsageDescription` Info.plist entries
// that make Bonjour advertise/browse actually WORK on-device (task brief:
// "do NOT add the... entitlements... here — that's PR-6") — it only defines
// the SERVICE TYPE STRING both actors' Network.framework calls reference,
// so there is exactly one literal to keep in sync with whatever PR-6 later
// puts in `appApple/project.yml`.
import Foundation
import Network

/// Shared Bonjour discovery constants for the LAN-direct TV remote.
public enum LANRemoteDiscovery {
    /// The Bonjour service type TVs advertise and remotes browse for.
    /// `_tcp` (not `_udp`) — every IHRP connection is a plain TCP+TLS
    /// stream (strategy §2.4.2's Network.framework client/server model).
    public static let bonjourServiceType = "_ihymns-remote._tcp"

    /// The documented default TCP port a TV listens on and a remote dials —
    /// strategy §2.4.5's "TCP 7269" (#1424, D-13). Was a bare `7269` integer
    /// literal duplicated across `TVRemoteControlCoordinator.swift` (the
    /// tvOS listener's own port), the manual-connect-by-address parser
    /// (`LANRemoteManualAddress.swift`), and the manual-connect sheet's
    /// pre-filled Port field — this is now the ONE literal all three read,
    /// per this repo's "extract first, use second" modularity rule.
    ///
    /// ELI5: "The one phone-number-like port number every iHymns TV remote
    /// uses, unless something else is already using it."
    public static let defaultPort: UInt16 = 7269
}

/// One discovered TV, as seen by a remote's `NWBrowser` — strategy §2.4.5:
/// "Remote caches last-good address per paired TV."
///
/// ELI5: "Here's a TV we found on the network, and how to reach it."
public struct LANRemoteDiscoveredService: Sendable, Equatable, Hashable {
    /// The Bonjour instance name (e.g. the TV's device name) —
    /// `.private` when logged (strategy's instrumentation contract:
    /// "instance names/IPs `.private`").
    public let name: String

    /// The endpoint an `NWConnection` can be built against directly.
    public let endpoint: NWEndpoint

    public init(name: String, endpoint: NWEndpoint) {
        self.name = name
        self.endpoint = endpoint
    }
}
