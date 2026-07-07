// SettingsViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Checks the object the Settings screen actually talks to — it starts
// out showing whatever was already saved, and the moment you change any
// setting on it, that change is written straight to disk (no separate "Save"
// button).
//
// DETAILED: Covers #182's `SettingsViewModel`. `@MainActor` because the view
// model is main-actor-isolated (it drives SwiftUI). Each test injects a
// store on a throwaway `UserDefaults(suiteName:)` so it can both PRE-SEED
// persisted values (to prove `init` loads them) and READ BACK the store
// after mutating the model (to prove each `didSet` persists).
import Foundation
import Testing
import IHAPI
import IHDesign
@testable import IHFeatures

@MainActor
@Suite("SettingsViewModel")
struct SettingsViewModelTests {

    /// A store + its backing defaults on a unique suite, returned together so
    /// a test can both seed the store and later read it back independently.
    private func makeStore() -> (IHSettingsStore, UserDefaults) {
        let defaults = UserDefaults(suiteName: "ihtest.settingsvm.\(UUID().uuidString)")!
        return (IHSettingsStore(defaults: defaults), defaults)
    }

    @Test("init loads the currently-persisted values")
    func initLoadsPersisted() {
        let (store, _) = makeStore()
        store.theme = .dark
        store.cvdMode = .tritanopia
        store.dyslexiaReadingModeEnabled = true
        store.analyticsConsentEnabled = true
        store.apiEnvironmentOverride = .prod

        let viewModel = SettingsViewModel(store: store)
        #expect(viewModel.theme == .dark)
        #expect(viewModel.cvdMode == .tritanopia)
        #expect(viewModel.dyslexiaReadingModeEnabled == true)
        #expect(viewModel.analyticsConsentEnabled == true)
        #expect(viewModel.apiEnvironmentOverride == .prod)
    }

    @Test("Changing any property persists it straight to the store")
    func mutationsPersist() {
        let (store, _) = makeStore()
        let viewModel = SettingsViewModel(store: store)

        viewModel.theme = .light
        viewModel.cvdMode = .protanopia
        viewModel.dyslexiaReadingModeEnabled = true
        viewModel.analyticsConsentEnabled = true
        viewModel.apiEnvironmentOverride = .beta

        // The store is the source of truth — a fresh read of it must reflect
        // every change the view model's `didSet` observers wrote through.
        #expect(store.theme == .light)
        #expect(store.cvdMode == .protanopia)
        #expect(store.dyslexiaReadingModeEnabled == true)
        #expect(store.analyticsConsentEnabled == true)
        #expect(store.apiEnvironmentOverride == .beta)
    }

    @Test("Clearing the environment override on the model clears it in the store")
    func clearingEnvironmentOverride() {
        let (store, _) = makeStore()
        let viewModel = SettingsViewModel(store: store)
        viewModel.apiEnvironmentOverride = .prod
        #expect(store.apiEnvironmentOverride == .prod)
        viewModel.apiEnvironmentOverride = nil
        #expect(store.apiEnvironmentOverride == nil)
    }

    @Test("The build-default environment is honest for the compile config")
    func buildDefaultEnvironment() {
        // Documents the contract `IHymnsApp` relies on: DEBUG builds default
        // to dev, everything else to prod. Whichever this test binary is, it
        // must be one of the two — never `.beta` (which only the DEBUG picker
        // can select).
        #if DEBUG
        #expect(APIEnvironment.defaultForBuild == .dev)
        #else
        #expect(APIEnvironment.defaultForBuild == .prod)
        #endif
    }
}
