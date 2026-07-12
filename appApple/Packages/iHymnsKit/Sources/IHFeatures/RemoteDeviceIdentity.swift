// RemoteDeviceIdentity.swift
// IHFeatures
//
// ELI5: "What kind of device is this, and what should we call it?" — the two
// little facts this app sends the TV when it pairs (`hello`/`pairConfirm`),
// so a TV's trusted-remotes list can show a sensible icon and name instead
// of a bare token.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §6.4). Platform-
// conditional the same way `TVRemoteControlCoordinator.deviceNameStatic`
// (`IHFeatures`) already is for the TV side — this is the phone/iPad/Mac/
// Vision mirror of that. Compiles on every platform `IHFeatures` targets
// (`Package.swift`'s `platforms:` list includes tvOS/watchOS too), even
// though only the `iHymns` app shell (iOS/iPadOS/macOS/visionOS) actually
// constructs a `RemoteControlSession` with these values — the `#if
// os(watchOS)`/`#else` branches below are DEFENSIVE (PR-11's future Watch
// -relay caller, and dead-code-but-compiling on tvOS), not load-bearing yet.
#if os(iOS) || os(visionOS)
import UIKit
#elseif os(macOS)
import Foundation
#elseif os(watchOS)
import WatchKit
#endif
import IHLive

/// This device's `IHRPRemoteKind` + cosmetic name, for the pairing
/// ceremony's `hello`/`pairConfirm` fields.
///
/// ELI5: "Are we an iPhone, iPad, Mac, or Vision Pro — and what's our name?"
public enum RemoteDeviceIdentity {

    /// The `IHRPRemoteKind` this device identifies as.
    public static var kind: IHRPRemoteKind {
        #if os(iOS)
        UIDevice.current.userInterfaceIdiom == .pad ? .pad : .phone
        #elseif os(visionOS)
        .vision
        #elseif os(macOS)
        .mac
        #elseif os(watchOS)
        // Defensive — PR-11's future Watch-relay caller; a watch never
        // opens a direct `NWConnection` itself (strategy §2.4.2), so this
        // value is never actually READ by anything shipping today.
        .watchRelay
        #else
        // tvOS compile-only — `IHFeatures` builds for every platform
        // (`Package.swift`), but the TV never constructs a
        // `RemoteControlSession` (it's the LISTENER, not a remote); this
        // case is dead code kept solely so the package compiles there.
        .phone
        #endif
    }

    /// A best-effort, cosmetic device name — `nil` only if the platform API
    /// itself returns nothing.
    ///
    /// - Note: On iOS 16+, `UIDevice.current.name` returns the GENERIC model
    ///   name ("iPhone"), not the user's personalised device name, unless the
    ///   app holds a private entitlement Apple does not grant third-party
    ///   apps — acceptable here since this is purely cosmetic (the TV's
    ///   trusted-remotes list), never a security boundary.
    public static var name: String? {
        #if os(iOS) || os(visionOS)
        UIDevice.current.name
        #elseif os(macOS)
        Host.current().localizedName
        #elseif os(watchOS)
        WKInterfaceDevice.current().name
        #else
        nil
        #endif
    }
}
