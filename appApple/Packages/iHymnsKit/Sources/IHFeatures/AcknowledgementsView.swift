// AcknowledgementsView.swift
// IHFeatures
//
// ELI5: The "who else's code is in here" page — lists the third-party
// pieces the app actually ships, which licence each one is under, and
// which app version/build you're currently running.
//
// DETAILED: #190 (Apple Phase 1 — "Acknowledgements / open-source licenses
// screen"). A thin renderer over `IHAppSupport.IHAcknowledgements.all()` —
// see that type's own header for exactly why GRDB.swift and OpenDyslexic
// are the two (and only two) entries. The version footer reuses
// `IHAppVersion.marketingAndBuild` (extracted from `SettingsView`'s
// previously-private `appVersion` the moment THIS screen became a second
// consumer — the repo's modularity rule).
import IHAppSupport
import SwiftUI

/// Third-party open-source acknowledgements, reachable from Settings.
public struct AcknowledgementsView: View {
    public init() {}

    public var body: some View {
        List {
            Section {
                ForEach(IHAcknowledgements.all()) { acknowledgement in
                    VStack(alignment: .leading, spacing: 4) {
                        Text(acknowledgement.name)
                            .font(.headline)
                        Text(acknowledgement.licenseName)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                        if let note = acknowledgement.note {
                            Text(note)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .padding(.vertical, 4)
                }
            } header: {
                Text("Open-Source Components")
            } footer: {
                Text("iHymns \(IHAppVersion.marketingAndBuild)")
            }
        }
        .navigationTitle("Acknowledgements")
    }
}
