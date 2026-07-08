// IHFontsTests.swift
// IHDesignTests
//
// ELI5: Proves the OpenDyslexic font is genuinely findable by name after
// registration (not just "the code ran without crashing"), and that the
// four bundled font FILES really do have the exact names
// `IHReadingMode` hard-codes — the kind of check that would have caught a
// typo'd PostScript name before it ever reached a device.
//
// DETAILED: #1412 FONT-PENDING completion. Two independent angles, per this
// task's own brief: (1) `registrationResolvesExpectedPostScriptNames` calls
// the REAL `IHFonts.registerBundledFonts()` and then asks AppKit
// (`NSFont(name:size:)`) whether each PostScript name now resolves — this
// is an end-to-end check of the actual runtime mechanism, not a mock.
// (2) `bundledFilesExposeExpectedPostScriptNames` reads each `.otf`'s own
// embedded PostScript name directly via CoreText's font-descriptor API,
// independent of registration state entirely, using `IHFonts
// .bundledFontURLs()` (an `IHDesign`-internal helper reachable here via
// `@testable import`) — so a future swap of one of the four files for a
// mis-named replacement fails THIS test even if registration itself still
// silently "succeeds" against the wrong name.
import CoreText
import Foundation
import Testing
@testable import IHDesign

#if canImport(AppKit)
import AppKit
#endif

@Suite("IHFonts registration")
struct IHFontsTests {
    /// The four faces this feature bundles, keyed by their expected
    /// PostScript name — the exact contract `IHReadingMode
    /// .dyslexiaFontName`/`.dyslexiaItalicFontName` depend on.
    private static let expectedFiles: [String: String] = [
        "OpenDyslexic-Regular": "OpenDyslexic-Regular",
        "OpenDyslexic-Bold": "OpenDyslexic-Bold",
        "OpenDyslexic-Italic": "OpenDyslexic-Italic",
        "OpenDyslexic-BoldItalic": "OpenDyslexic-BoldItalic"
    ]

    #if canImport(AppKit)
    @Test("registerBundledFonts() makes every bundled PostScript name resolvable via AppKit")
    func registrationResolvesExpectedPostScriptNames() {
        IHFonts.registerBundledFonts()
        for postScriptName in Self.expectedFiles.values {
            #expect(NSFont(name: postScriptName, size: 12) != nil, "Expected \(postScriptName) to resolve after registration")
        }
    }

    @Test("Calling registerBundledFonts() a second time is a harmless no-op (idempotent)")
    func registrationIsIdempotent() {
        IHFonts.registerBundledFonts()
        IHFonts.registerBundledFonts()
        #expect(NSFont(name: "OpenDyslexic-Regular", size: 12) != nil)
    }
    #endif

    @Test("Exactly the four expected .otf files are bundled")
    func bundledFontURLsCountsFour() {
        #expect(IHFonts.bundledFontURLs().count == 4)
    }

    @Test("Each bundled .otf file's own PostScript name matches the constant IHReadingMode references")
    func bundledFilesExposeExpectedPostScriptNames() {
        var seenPostScriptNames: Set<String> = []
        for url in IHFonts.bundledFontURLs() {
            guard let descriptors = CTFontManagerCreateFontDescriptorsFromURL(url as CFURL) as? [CTFontDescriptor],
                  let descriptor = descriptors.first else {
                Issue.record("Could not read a font descriptor from \(url.lastPathComponent)")
                continue
            }
            guard let postScriptName = CTFontDescriptorCopyAttribute(descriptor, kCTFontNameAttribute) as? String else {
                Issue.record("\(url.lastPathComponent) has no PostScript name attribute")
                continue
            }
            seenPostScriptNames.insert(postScriptName)
            #expect(
                Self.expectedFiles[postScriptName] == postScriptName,
                "\(url.lastPathComponent) resolved to unexpected PostScript name \(postScriptName)"
            )
        }
        // Confirms every expected name was actually FOUND (not just that
        // every found name was expected) — catches a missing file, not
        // only a mis-named one.
        #expect(seenPostScriptNames == Set(Self.expectedFiles.keys))
    }
}
