// InstallIdForViewsTests.swift
// IHAuthTests
//
// ELI5: Checks the controller hands out this device's random name-tag for the
// anonymous song-view report, and hands out nothing when device sign-in is not
// in use.
//
// DETAILED: 2026-11-24 owner decision (song views are reported signed out too).
// `installIdIfAvailable()` never throws: no device sign-in support (plain mode)
// answers `nil`, support answers the install id. Same harness as the other
// device sign-in tests.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("Install id for anonymous song views", .serialized)
struct InstallIdForViewsTests {

    @Test("With device sign-in support, the install id is returned")
    func returnsInstallId() async throws {
        let harness = try await DeviceTestData.makeHarness()
        let installId = await harness.controller.installIdIfAvailable()
        #expect(installId == "install-1234-abcd")
    }

    @Test("In plain mode there is no install id to send")
    func plainModeHasNone() async throws {
        let harness = try await DeviceTestData.makeHarness(plain: true)
        let installId = await harness.controller.installIdIfAvailable()
        #expect(installId == nil)
    }
}
