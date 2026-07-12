// LANRemoteAddress.swift
// IHLive/LANRemote
//
// ELI5: Figures out "what's this TV's own address on the home Wi-Fi right
// now?" — the number an operator could type into a phone's "connect by
// address" screen if Bonjour discovery isn't finding the TV for some
// reason. Also holds the small "host + port" shape a REMOTE records once it
// actually resolves and connects to one.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §2). Feeds the
// Settings "Remote Control info" panel (strategy §2.4.5) —
// `TVRemoteControlCoordinator.ListenerInfo.ipAddress` (`IHFeatures`) — and
// is reused as-is by PR-8's manual connect-by-address rung. `getifaddrs`
// (POSIX, https://developer.apple.com/library/archive/documentation/System/Conceptual/ManPages_iPhoneOS/man3/getifaddrs.3.html)
// enumerates every network interface on the device; this walks that list
// ONCE, skips loopback (meaningless to show a remote user), and PREFERS
// `en0` (the primary Wi-Fi/Ethernet interface on tvOS/iOS — Apple's own
// convention) while still falling back to some OTHER interface's address
// rather than `nil` if `en0` isn't up. Never throws — a Settings screen
// showing "address unavailable" is a reasonable degraded UI, not a crash.
//
// #1422 UPDATE (`.claude/apple-phase2-pr7-spec.md` §2) — adds
// `LANRemoteResolvedAddress`, the tiny value `RemoteSessionActor
// +Connection.swift` captures off `NWConnection.currentPath?.remoteEndpoint`
// once a connection reaches `.ready`. It lives HERE (beside the existing
// address helper, "one address-shaped file" per that spec section) even
// though it has nothing to do with `getifaddrs` — both types answer the same
// question ("how do you reach a device on this LAN"), just from opposite
// ends of the connection.
import Foundation

/// A resolved `host:port` pair for an ACTUAL live (or just-was-live)
/// connection — as opposed to `LANRemotePairingPayload`'s `host`/`port`,
/// which are the TV's own best-effort self-report at pairing time.
///
/// ELI5: "The exact address this connection is actually using right now."
///
/// DETAILED: Captured by `RemoteSessionActor+Connection.swift` from
/// `NWConnection.currentPath?.remoteEndpoint` the instant a connection
/// reaches `.ready` — this is what actually worked, which may differ from
/// whatever `NWEndpoint` (a Bonjour `.service` result, say) was originally
/// dialled. `PairedTVRecord.lastAddress` (`PairedTVStore.swift`) persists the
/// most recent one of these per paired TV, and `RemoteControlSession
/// +Link.swift`'s reconnect ladder alternates between the primary endpoint
/// and this saved last-good address so a TV that temporarily lost its
/// Bonjour registration is still reachable.
public struct LANRemoteResolvedAddress: Sendable, Equatable, Codable {
    public let host: String
    public let port: UInt16

    public init(host: String, port: UInt16) {
        self.host = host
        self.port = port
    }
}

/// Best-effort local-network address lookup for this device.
///
/// ELI5: "What's this TV's own network address right now?"
public enum LANRemoteAddress {
    /// This device's primary IPv4 address, or `nil` if none can be
    /// determined (no active interface, or a platform/sandboxing
    /// restriction on `getifaddrs`).
    public static func primaryIPv4Address() -> String? {
        var ifaddrPtr: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&ifaddrPtr) == 0, let firstAddr = ifaddrPtr else { return nil }
        defer { freeifaddrs(ifaddrPtr) }

        var fallback: String?
        var pointer: UnsafeMutablePointer<ifaddrs>? = firstAddr
        while let interface = pointer {
            defer { pointer = interface.pointee.ifa_next }
            guard let socketAddress = interface.pointee.ifa_addr,
                  socketAddress.pointee.sa_family == UInt8(AF_INET),
                  let address = ipv4String(from: socketAddress),
                  address != "127.0.0.1" else { continue }

            let name = String(cString: interface.pointee.ifa_name)
            if name == "en0" { return address }
            if fallback == nil { fallback = address }
        }
        return fallback
    }

    /// Extracts the dotted-decimal IPv4 string from a `sockaddr` already
    /// confirmed `sa_family == AF_INET` — `inet_ntop` (POSIX,
    /// https://man7.org/linux/man-pages/man3/inet_ntop.3.html) does the
    /// actual byte-to-string conversion; `nil` only if that somehow fails
    /// (malformed interface data), never expected in practice.
    private static func ipv4String(from socketAddress: UnsafeMutablePointer<sockaddr>) -> String? {
        var addressIn = UnsafeRawPointer(socketAddress).assumingMemoryBound(to: sockaddr_in.self).pointee
        var buffer = [CChar](repeating: 0, count: Int(INET6_ADDRSTRLEN))
        guard inet_ntop(AF_INET, &addressIn.sin_addr, &buffer, socklen_t(buffer.count)) != nil else { return nil }
        // `String(cString:)` on a raw `[CChar]` array is deprecated;
        // go through the buffer's own null-terminated pointer instead.
        return buffer.withUnsafeBufferPointer { String(cString: $0.baseAddress!) }
    }
}
