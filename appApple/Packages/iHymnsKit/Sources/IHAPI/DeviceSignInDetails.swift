// DeviceSignInDetails.swift
// IHAPI
//
// ELI5: The little "who is signing in" card the app attaches to a sign-in so
// the server can list this device properly: a random install id, "apple",
// a plain device name like "iPhone", and the app's version number.
//
// DETAILED: PR 3b step I2b (#2180). Until now the Apple app sent NO device
// details with a sign-in, so every Apple sign-in showed as "Unnamed device"
// and none could be tied to an install. The server reads four optional body
// fields (`apiTokenDeviceMetaFromBody()` in `includes/api_tokens.php`):
// `deviceInstallId` (8 to 64 letters, digits and dashes), `platform`
// (`"apple"`), `deviceName` (up to 120 characters), and `appVersion`
// (`^[A-Za-z0-9.\-]{1,20}$`). Anything malformed is quietly dropped there, so
// this type cleans its values the same way first, so a bad value is left out
// by us rather than lost silently by the server.
//
// The device name is deliberately GENERIC ("iPhone", "iPad", "Mac", "Apple
// TV"), never the person's own device name. iOS 16 and later hide the real
// name anyway without a special entitlement (plan section 1, item 14), and a
// person can rename the device on the web. It is a plain model word, not
// something that identifies a person. `UIKit`/`UIDevice` is not used because
// its properties are main-actor-only in Swift 6; the model word is read from
// the kernel's machine string (`uname`) instead, through a small PURE function
// that is easy to test.
import Foundation

/// The device details a sign-in carries.
///
/// ELI5: "I am install 1234, an Apple device called iPhone, running app 1.0.0."
public struct DeviceSignInDetails: Sendable, Equatable {
    /// This install's random id (see `IHAuth.DeviceIdentity`).
    public let installId: String
    /// Always `"apple"` for this app. The server refuses `"web"` here.
    public let platform: String
    /// A generic model word, or `nil` to leave it out.
    public let deviceName: String?
    /// The app's marketing version, or `nil` to leave it out.
    public let appVersion: String?

    public init(installId: String, deviceName: String?, appVersion: String?) {
        self.installId = installId
        self.platform = "apple"
        self.deviceName = deviceName
        self.appVersion = Self.sanitizedAppVersion(appVersion)
    }

    /// The details for THIS running app: the given install id, the generic
    /// model word for this device, and `CFBundleShortVersionString`.
    public static func current(installId: String) -> DeviceSignInDetails {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String
        return DeviceSignInDetails(installId: installId, deviceName: genericDeviceName(), appVersion: version)
    }

    /// The server's own rule for an app version, applied here first. PURE.
    nonisolated static func sanitizedAppVersion(_ raw: String?) -> String? {
        guard let trimmed = raw?.trimmingCharacters(in: .whitespacesAndNewlines), !trimmed.isEmpty, trimmed.count <= 20 else {
            return nil
        }
        let allowed = trimmed.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber || $0 == "." || $0 == "-") }
        return allowed ? trimmed : nil
    }

    /// A plain model word for this device. The platform decides everywhere
    /// except iOS, where the kernel's machine string tells an iPad from an
    /// iPhone.
    nonisolated static func genericDeviceName() -> String {
        #if os(macOS)
        return "Mac"
        #elseif os(tvOS)
        return "Apple TV"
        #elseif os(watchOS)
        return "Apple Watch"
        #elseif os(visionOS)
        return "Apple Vision Pro"
        #else
        return genericDeviceName(forMachine: machineIdentifier())
        #endif
    }

    /// The iOS branch, as a pure function so a test can feed it strings.
    /// "iPad14,3" gives "iPad", "iPhone16,1" or "iPod9,1" gives "iPhone", and
    /// anything else (a simulator's "arm64", say) gives "Apple device" rather
    /// than a wrong guess.
    nonisolated static func genericDeviceName(forMachine machine: String) -> String {
        if machine.hasPrefix("iPad") { return "iPad" }
        if machine.hasPrefix("iPhone") || machine.hasPrefix("iPod") { return "iPhone" }
        return "Apple device"
    }

    /// The kernel's machine string ("iPhone16,1"), or an empty string if it cannot be read.
    ///
    /// DETAILED: `utsname.machine` is a fixed-size C character array that Swift
    /// shows as a tuple, so it is read through `Mirror`, keeping every non-zero
    /// byte. This is the long-standing standard way to read it, and it avoids
    /// unsafe pointer code.
    nonisolated private static func machineIdentifier() -> String {
        var info = utsname()
        uname(&info)
        return Mirror(reflecting: info.machine).children.reduce(into: "") { result, element in
            guard let value = element.value as? Int8, value != 0 else { return }
            result.append(Character(UnicodeScalar(UInt8(bitPattern: value))))
        }
    }
}
