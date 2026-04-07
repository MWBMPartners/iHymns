// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  LegalViews.swift
//  iHymns
//
//  Privacy Policy, Terms of Use, and First-Launch CCLI Disclaimer views.
//  Content is bundled for offline access.
//

import SwiftUI

// MARK: - PrivacyPolicyView

struct PrivacyPolicyView: View {

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Spacing.lg) {
                Text("Privacy Policy")
                    .font(.largeTitle.bold())

                Text("Last updated: April 2026")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                legalSection("1. Information We Collect",
                    "iHymns collects minimal data to provide its features. We do not require account registration. All personal preferences (favourites, set lists, search history) are stored locally on your device.")

                legalSection("2. Analytics",
                    "With your consent, we use Plausible Analytics (privacy-focused, cookieless) and Supabase to understand app usage. No personally identifiable information is collected. You can opt out at any time in Settings.")

                legalSection("3. App Tracking Transparency",
                    "On iOS, we request App Tracking Transparency permission before enabling any analytics. If denied, no tracking occurs.")

                legalSection("4. Data Storage",
                    "All user data (favourites, set lists, preferences) is stored locally using UserDefaults. No data is transmitted to our servers without your explicit action (e.g., sharing a set list).")

                legalSection("5. Shared Set Lists",
                    "When you share a set list, the song IDs and set list name are stored on our server. A unique owner ID (anonymous UUID) is associated for edit permissions. No personal information is included.")

                legalSection("6. Third-Party Services",
                    "Plausible Analytics: plausible.io (EU-hosted, GDPR compliant)\nSupabase: supabase.com (SOC 2 compliant)\nNo data is shared with advertisers or data brokers.")

                legalSection("7. Cookies",
                    "The iHymns app does not use cookies. Web analytics via Plausible are cookieless by design.")

                legalSection("8. Children's Privacy",
                    "iHymns does not knowingly collect personal information from children under 13. The app is suitable for all ages.")

                legalSection("9. Data Retention",
                    "Local data persists until you clear it or uninstall the app. Shared set lists are retained on the server indefinitely unless deleted by the owner.")

                legalSection("10. Your Rights",
                    "You can export all your data via Settings > Export Backup. You can delete all local data by uninstalling the app. For shared set list deletion, contact us.")

                legalSection("11. Security",
                    "All network communication uses HTTPS with TLS 1.3. Local data is protected by iOS/macOS device encryption.")

                legalSection("12. Contact",
                    "For privacy inquiries: privacy@mwbmpartners.ltd\nWebsite: ihymns.app\nDeveloper: MWBM Partners Ltd")

                Text(AppInfo.Application.Copyright.full)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .padding(.top, Spacing.lg)
            }
            .padding()
        }
        .navigationTitle("Privacy Policy")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.inline)
        #endif
    }
}

// MARK: - TermsOfUseView

struct TermsOfUseView: View {

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Spacing.lg) {
                Text("Terms of Use")
                    .font(.largeTitle.bold())

                Text("Last updated: April 2026")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                legalSection("1. Acceptance",
                    "By using iHymns, you agree to these Terms of Use. If you do not agree, please do not use the application.")

                legalSection("2. Licence",
                    "iHymns is licensed as Freeware for personal, non-commercial use. The application and its content are proprietary to MWBM Partners Ltd.")

                legalSection("3. Song Lyrics",
                    "Song lyrics are included under licence from copyright holders. Lyrics are provided for personal worship and reference only. Reproduction, redistribution, or commercial use of lyrics is prohibited without permission from the respective copyright holders.")

                legalSection("4. CCLI Compliance",
                    "If you display lyrics publicly (e.g., in a church service using Presentation Mode), you are responsible for holding an appropriate CCLI licence or equivalent copyright permission.")

                legalSection("5. Offline Use",
                    "Song data is bundled with the app for offline access. You may not extract, copy, or redistribute the bundled data.")

                legalSection("6. Set Lists & Sharing",
                    "You may create and share set lists for worship planning. Shared set lists are public via their URL. You are responsible for the content you share.")

                legalSection("7. User Conduct",
                    "You agree to use iHymns respectfully and in accordance with applicable laws. You may not attempt to reverse-engineer, decompile, or modify the application.")

                legalSection("8. Third-Party Libraries",
                    "iHymns uses open-source libraries under their respective licences. These include SwiftUI framework components provided by Apple.")

                legalSection("9. Analytics",
                    "With your consent, anonymised usage analytics are collected to improve the app. See our Privacy Policy for details.")

                legalSection("10. Disclaimers",
                    "iHymns is provided \"as is\" without warranty. We do not guarantee the accuracy or completeness of lyrics or metadata.")

                legalSection("11. Limitation of Liability",
                    "MWBM Partners Ltd shall not be liable for any indirect, incidental, or consequential damages arising from your use of iHymns.")

                legalSection("12. Changes",
                    "We may update these terms at any time. Continued use constitutes acceptance of updated terms.\n\nContact: legal@mwbmpartners.ltd")

                Text(AppInfo.Application.Copyright.full)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .padding(.top, Spacing.lg)
            }
            .padding()
        }
        .navigationTitle("Terms of Use")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.inline)
        #endif
    }
}

// MARK: - FirstLaunchDisclaimerView

/// CCLI disclaimer shown on first launch. Must be accepted before using the app.
struct FirstLaunchDisclaimerView: View {

    let onAccept: () -> Void

    var body: some View {
        VStack(spacing: Spacing.xl) {
            Spacer()

            Image(systemName: "music.note.list")
                .font(.system(size: 60))
                .foregroundStyle(AmberTheme.accent)

            Text("Welcome to iHymns")
                .font(.title.bold())

            Text("iHymns provides hymn and worship song lyrics from multiple songbooks for personal and congregational use.")
                .font(.body)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)

            VStack(alignment: .leading, spacing: Spacing.md) {
                Text("Important Notice")
                    .font(.headline)

                Text("Song lyrics are included under licence from their respective copyright holders. If you display lyrics publicly (e.g., during a church service), you are responsible for holding a valid CCLI licence or equivalent copyright permission.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                Text("By continuing, you acknowledge this responsibility and agree to our Terms of Use.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            .padding()
            .liquidGlass(.regular, tint: AmberTheme.wash)

            Spacer()

            VStack(spacing: Spacing.md) {
                LiquidGlassButton("I Understand — Continue", systemImage: "checkmark.circle") {
                    onAccept()
                }

                HStack(spacing: Spacing.lg) {
                    NavigationLink("Privacy Policy", destination: PrivacyPolicyView())
                        .font(.caption)
                    NavigationLink("Terms of Use", destination: TermsOfUseView())
                        .font(.caption)
                }
                .foregroundStyle(.secondary)
            }
        }
        .padding(Spacing.xl)
    }
}

// MARK: - Disclaimer Persistence

enum DisclaimerManager {
    private static let key = "ihymns_disclaimer_accepted"

    static var isAccepted: Bool {
        UserDefaults.standard.bool(forKey: key)
    }

    static func markAccepted() {
        UserDefaults.standard.set(true, forKey: key)
    }
}

// MARK: - Legal Section Helper

private func legalSection(_ title: String, _ body: String) -> some View {
    VStack(alignment: .leading, spacing: Spacing.sm) {
        Text(title)
            .font(.headline)
        Text(body)
            .font(.subheadline)
            .foregroundStyle(.secondary)
    }
}
