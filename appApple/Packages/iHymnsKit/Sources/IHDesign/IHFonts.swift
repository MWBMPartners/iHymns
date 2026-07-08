// IHFonts.swift
// IHDesign
//
// ELI5: SwiftUI can't find the OpenDyslexic font just because its file is
// inside the app — someone has to explicitly say "hey system, here's a font
// file, please add it to your list of known fonts" once when the app
// starts. This file is that one-time "please add it" call.
//
// DETAILED: #1412 (dyslexia-friendly reading mode) FONT-PENDING completion.
// `Package.swift`'s `.copy("Resources/Fonts")` bundles the four OpenDyslexic
// OTFs (+ `OpenDyslexic-OFL.txt`) into `Bundle.module` under a `Fonts/`
// subdirectory, but that alone is NOT enough for `Font.custom(_:size:)` to
// resolve them — an app-TARGET's own `Info.plist` can auto-register bundled
// fonts via the `UIAppFonts` key (Apple's "Fonts" build phase), but a
// SwiftPM PACKAGE resource bundle has no such mechanism: `Bundle.module`'s
// contents are just files sitting in the app bundle until something calls
// CoreText's `CTFontManagerRegisterFontURLs(_:_:_:_:)` to register them at
// runtime (https://developer.apple.com/documentation/coretext/1499288-ctfontmanagerregisterfonturls).
// That is the ENTIRE reason this file exists — every app shell's `init()`
// calls `IHFonts.registerBundledFonts()` once before any lyric view renders
// `Font.custom("OpenDyslexic-Regular", size:)` (`IHReadingMode.lyricFont`),
// so the name resolves to the real bundled face instead of silently falling
// back to the system font.
import CoreText
import Foundation

/// Registers the bundled OpenDyslexic OTFs with CoreText so
/// `Font.custom(_:size:)` can resolve them by PostScript name.
///
/// ELI5: Call `IHFonts.registerBundledFonts()` once when your app/extension
/// starts, and the dyslexia-friendly font will actually show up.
public enum IHFonts {
    /// Guards `registerBundledFonts()` against doing its (cheap but
    /// pointless) work twice within the SAME process.
    ///
    /// ELI5: A little checkbox — "did we already do this?" — so calling the
    /// register function a second time by accident is a harmless no-op.
    ///
    /// DETAILED: Swift 6 strict concurrency treats a mutable `static var` as
    /// unsafe global state UNLESS it's isolated to a global actor or the
    /// caller opts out explicitly. This is deliberately `nonisolated(unsafe)`
    /// + hand-synchronized with `lock` below (rather than `@MainActor`-
    /// isolating the whole enum) because `registerBundledFonts()` needs to
    /// be callable from every context that might render a lyric FIRST —
    /// the main `iHymns` shell's `App.init()`, the `iHymnsWidgets` extension
    /// (its own separate process, off the main thread inside
    /// `TimelineProvider` callbacks), and `IHDesignTests` — without forcing
    /// every one of those callers onto the main actor just to flip a
    /// boolean. `CTFontManagerRegisterFontURLs` itself is a plain C API
    /// with no main-thread requirement (Apple's reference documents no such
    /// constraint, unlike, say, `UIFont`/`NSFont` UI-object construction),
    /// so pinning this to `@MainActor` would add a real restriction for no
    /// real safety benefit — the lock below is the minimal correct
    /// synchronization instead.
    nonisolated(unsafe) private static var didRegister = false

    /// Serializes access to `didRegister` across concurrent callers (see
    /// above) — an `NSLock` rather than an `actor` because this is a single
    /// boolean guarding a synchronous, non-`async` C call; wrapping it in an
    /// actor would force every caller to `await` for no benefit.
    private static let lock = NSLock()

    /// Registers every bundled `.otf` under `Fonts/` with CoreText.
    ///
    /// ELI5: "Go find the font files we shipped, and tell the system about
    /// them." Safe to call more than once (does nothing after the first
    /// real call) and safe to call from anywhere — if registration fails
    /// for any reason (already registered by another code path, a corrupt
    /// file, running inside a sandboxed test host, ...) this simply returns
    /// without crashing, exactly like the "unregistered family name" case
    /// `Font.custom` already handles by falling back to the system font
    /// (`IHReadingMode.swift`'s header) — bundling the real font only ever
    /// IMPROVES on that fallback, it never introduces a new failure mode.
    ///
    /// DETAILED: `Bundle.module.urls(forResourcesWithExtension:subdirectory:)`
    /// finds the four copied OTFs regardless of which app/extension bundle
    /// embeds `IHDesign`'s resource bundle. `CTFontManagerRegisterFontURLs`'s
    /// `.process` scope registers them for the current process only (not
    /// persistently system-wide) with `true` for "enabled" — the correct,
    /// documented combination for an app registering its own bundled fonts.
    public static func registerBundledFonts() {
        lock.lock()
        defer { lock.unlock() }
        guard !didRegister else { return }
        didRegister = true

        let urls = bundledFontURLs()
        guard !urls.isEmpty else { return }
        // `errors:` (the trailing `CFArray?` out-param) is intentionally
        // discarded (`nil`) — a per-file registration failure (e.g. a face
        // already registered by a previous, non-idempotent caller) is
        // exactly the "best-effort, never crash" contract this function
        // promises; `Font.custom` degrading to the system font is an
        // acceptable, already-documented outcome, not something worth
        // surfacing here.
        CTFontManagerRegisterFontURLs(urls as CFArray, .process, true, nil)
    }

    /// The bundled `.otf` file URLs under `Fonts/` — factored out of
    /// `registerBundledFonts()` so `IHFontsTests` can independently read
    /// each file's real PostScript name (via `CTFontManagerCreateFont
    /// DescriptorsFromURL`) WITHOUT going through registration, proving the
    /// bundled files themselves match the names `IHReadingMode` hard-codes
    /// even if registration were silently skipped. Not `public` — this is
    /// an `IHDesign`-internal implementation detail, reachable from tests
    /// only via `@testable import`.
    static func bundledFontURLs() -> [URL] {
        Bundle.module.urls(forResourcesWithExtension: "otf", subdirectory: "Fonts") ?? []
    }
}
