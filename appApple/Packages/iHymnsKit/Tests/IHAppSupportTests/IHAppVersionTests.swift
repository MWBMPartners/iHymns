// IHAppVersionTests.swift
// IHAppSupportTests
//
// ELI5: Checks the "what version is this?" helper always returns SOMETHING
// sensibly shaped, even in a test host with no real app Info.plist.
import Testing
@testable import IHAppSupport

@Suite("IHAppVersion")
struct IHAppVersionTests {

    @Test("marketingAndBuild is always the \"<version> (<build>)\" shape, never empty")
    func alwaysWellShaped() {
        let value = IHAppVersion.marketingAndBuild
        #expect(!value.isEmpty)
        #expect(value.contains("("))
        #expect(value.contains(")"))
    }
}
