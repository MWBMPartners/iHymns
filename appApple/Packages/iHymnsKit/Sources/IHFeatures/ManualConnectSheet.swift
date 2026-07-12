// ManualConnectSheet.swift
// IHFeatures
//
// ELI5: The "type in your TV's address" form — an Address box, a Port box
// (already filled in with the normal default), and a Connect button that
// won't even try until what you typed looks like a real address.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §6.2). The
// `PairingPayloadEntrySheet` idiom, verbatim posture: `NavigationStack {
// Form }`, Cancel/Connect toolbar. Validates via the SAME
// `LANRemoteManualAddress.parse` the coordinator itself calls — this is
// deliberate double-parsing (this sheet's copy for an INLINE, non
// -dismissing error; the coordinator's own parse for the actual connect
// attempt), not a second implementation: both calls route through the one
// shared, pure parser (this repo's modularity rule), so they can never
// disagree about what's valid. No `Menu`/segmented picker/`DisclosureGroup`
// (the #1549 watchOS-compat rule every new IHFeatures file must honour).
import IHLive
import SwiftUI

/// The "Connect by Address" entry sheet — see this file's header.
public struct ManualConnectSheet: View {
    /// Called with the raw (unparsed) address/port text — the coordinator
    /// re-parses and starts the TOFU connect.
    let onConnect: (String, String?) -> Void
    let onCancel: () -> Void

    @State private var addressInput: String
    @State private var portInput: String
    @State private var parseErrorMessage: String?

    public init(onConnect: @escaping (String, String?) -> Void, onCancel: @escaping () -> Void) {
        self.onConnect = onConnect
        self.onCancel = onCancel
        _addressInput = State(initialValue: IHSettingsStore().lastManualConnectAddress ?? "")
        _portInput = State(initialValue: String(LANRemoteDiscovery.defaultPort))
    }

    public var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("192.168.1.50 or hall-tv.local", text: $addressInput)
                        #if os(iOS) || os(visionOS)
                        .textInputAutocapitalization(.never)
                        #endif
                        .autocorrectionDisabled()
                    TextField("Port", text: $portInput)
                        #if os(iOS) || os(visionOS)
                        .textInputAutocapitalization(.never)
                        #endif
                        .autocorrectionDisabled()
                } header: {
                    Text("TV Address")
                } footer: {
                    if let parseErrorMessage {
                        Text(parseErrorMessage).foregroundStyle(.red)
                    } else {
                        Text("The TV shows its address and port in Settings → Remote Control.")
                    }
                }

                Section {
                } footer: {
                    Text("If you can scan or paste the TV's QR code instead, prefer that — it verifies the TV automatically.")
                }
            }
            .navigationTitle("Connect by Address")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", role: .cancel, action: onCancel)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Connect", action: attemptConnect)
                        .disabled(addressInput.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
        }
    }

    private func attemptConnect() {
        switch LANRemoteManualAddress.parse(hostInput: addressInput, portInput: portInput) {
        case .failure(let error):
            parseErrorMessage = Self.errorCopy(for: error)
        case .success:
            parseErrorMessage = nil
            IHSettingsStore().lastManualConnectAddress = addressInput.trimmingCharacters(in: .whitespacesAndNewlines)
            onConnect(addressInput, portInput)
        }
    }

    private static func errorCopy(for error: LANRemoteManualAddress.ParseError) -> String {
        switch error {
        case .empty:
            return "Enter the TV's address."
        case .unsupportedScheme:
            return "Just the address, no “http://”."
        case .invalidHost:
            return "That doesn't look like a valid address."
        case .invalidPort:
            return "That port isn't valid — use a number between 1 and 65535."
        }
    }
}
