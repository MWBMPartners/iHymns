// ProfileSyncMapping.swift
// IHFeatures
//
// ELI5: The short list that says "this saved choice in the app IS that shared
// setting on the account": the theme picker is `appearance.theme`, the colour
// -vision mode is `appearance.cvd`, and so on, plus how to read each one from the
// device and write it back.
//
// DETAILED: PR 3b step I4 (#2180). The account's shared settings have ids
// (`section.key`) from one registry (`includes/profile_registry.php`, bundled as
// `IHModels/Resources/profile-schema.json`). Each Apple preference that should
// follow the person is bound here to ONE registry id and ONE `UserDefaults` key
// (the keys `IHSettingsStore` and the `@AppStorage` properties already use, so
// nothing about how the app stores a preference changes). Only settings the
// registry says Apple uses (platform `apple` or `all`) may be listed;
// `tests/test-profile-registry-lockstep.js` checks every id here is a real
// registry entry for Apple, that the `UserDefaults` key matches the one the
// settings store / `@AppStorage` uses, and that the value kinds and allowed
// values agree, so a rename on either side turns the build red instead of
// quietly stopping a setting from syncing.
//
// Kinds: `bool`, `double` and `string` pass the stored value straight through.
// `cvd` is the one special case: the registry has NO entry for "no adjustment"
// (an unset setting), while the app stores the word "none", so "none" and an
// absent key both read as ABSENT, and removing the setting resets it to
// absent. Nothing is ever read from, or written to, a key not listed here.
import Foundation
import IHModels

/// How a stored `UserDefaults` value maps to a registry value.
///
/// ELI5: The few shapes a setting takes.
enum ProfileBindingKind: Sendable, Equatable {
    case bool
    case double
    case string
    /// The colour-vision mode: "none" (or no key) is "no setting".
    case cvd
}

/// One preference bound to one shared setting.
///
/// ELI5: "`ihAppearanceTheme` on this device is `appearance.theme` on the account."
struct ProfileSettingBinding: Sendable, Equatable {
    /// The registry id, `section.key`.
    let id: String
    /// The `UserDefaults` key the app already stores it under.
    let defaultsKey: String
    let kind: ProfileBindingKind

    var section: String { String(id.split(separator: ".", maxSplits: 1)[0]) }
    var key: String { String(id.split(separator: ".", maxSplits: 1)[1]) }
}

/// The bindings the Apple app has. Every `id` must be a registry entry used by
/// Apple, and every `defaultsKey` must be the key the app really stores it under.
enum ProfileSettingBindings {
    static let all: [ProfileSettingBinding] = [
        ProfileSettingBinding(id: "appearance.theme", defaultsKey: "ihAppearanceTheme", kind: .string),
        ProfileSettingBinding(id: "appearance.cvd", defaultsKey: "ihCvdMode", kind: .cvd),
        ProfileSettingBinding(id: "reading.textScale", defaultsKey: "ihLyricsTextScale", kind: .double),
        ProfileSettingBinding(id: "reading.showChords", defaultsKey: "ihLyricsShowChords", kind: .bool),
        ProfileSettingBinding(id: "reading.dyslexia", defaultsKey: "ihDyslexiaReadingModeEnabled", kind: .bool)
    ]

    static func binding(id: String) -> ProfileSettingBinding? {
        all.first { $0.id == id }
    }
}

/// Reads and writes the bound preferences. A tiny `Sendable` wrapper over an
/// injectable `UserDefaults` (same reasoning as `RecentSearchesStore`).
///
/// ELI5: The hands that read a setting off the device and put one back.
struct ProfileLocalSettings: @unchecked Sendable {
    let defaults: UserDefaults

    init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
    }

    /// What this device holds for `binding`: a value, or `nil` for "not set".
    func read(_ binding: ProfileSettingBinding) -> ProfileValue? {
        guard let stored = defaults.object(forKey: binding.defaultsKey) else { return nil }
        switch binding.kind {
        case .bool:
            return (stored as? Bool).map(ProfileValue.bool)
        case .double:
            return (stored as? Double).map(ProfileValue.double)
        case .string:
            return (stored as? String).map(ProfileValue.string)
        case .cvd:
            guard let word = stored as? String, word != "none", !word.isEmpty else { return nil }
            return .string(word)
        }
    }

    /// Puts `value` on this device (`nil` clears the preference back to "not set").
    /// A value of the wrong shape for the binding is ignored (never written).
    func write(_ value: ProfileValue?, to binding: ProfileSettingBinding) {
        guard let value else {
            defaults.removeObject(forKey: binding.defaultsKey)
            return
        }
        switch (binding.kind, value) {
        case (.bool, .bool(let flag)):
            defaults.set(flag, forKey: binding.defaultsKey)
        case (.double, _):
            if let number = value.number { defaults.set(number, forKey: binding.defaultsKey) }
        case (.string, .string(let text)), (.cvd, .string(let text)):
            defaults.set(text, forKey: binding.defaultsKey)
        default:
            break
        }
    }
}
