// LegalView.swift
// IHFeatures
//
// ELI5: The "Privacy Policy" and "Terms of Use" page — both link straight
// to the real pages on the iHymns website instead of the app carrying its
// own (possibly stale) copy.
//
// DETAILED: #190 (Apple Phase 1 — "Legal: Privacy Policy + Terms links (to
// the real web pages if they exist)"). Both pages DO exist and already ship
// (`appWeb/public_html/includes/pages/privacy.php` /
// `includes/pages/terms.php`, routed at `/privacy` / `/terms`) — verified by
// reading both files, not assumed. Deliberately links OUT via
// `CanonicalURL.privacyPolicy`/`.termsOfUse` rather than bundling native
// copies of the text: a bundled copy can only ever be updated by shipping a
// new app version, so it WILL eventually drift from the real, legally
// operative policy the web app (and every other iHymns surface) already
// serves live.
import IHAppSupport
import SwiftUI

/// Privacy Policy + Terms of Use, reachable from Settings.
public struct LegalView: View {
    public init() {}

    public var body: some View {
        List {
            Section {
                if let url = CanonicalURL.privacyPolicy {
                    Link(destination: url) {
                        Label("Privacy Policy", systemImage: "hand.raised")
                    }
                }
                if let url = CanonicalURL.termsOfUse {
                    Link(destination: url) {
                        Label("Terms of Use", systemImage: "doc.text")
                    }
                }
            } footer: {
                Text("Both open in your browser. iHymns maintains the current Privacy Policy and Terms of Use on its website so they're always up to date — this app never bundles a copy that could go stale.")
            }
        }
        .navigationTitle("Legal")
    }
}
