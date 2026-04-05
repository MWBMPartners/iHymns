/**
 * iHymns — Application Version & Information (Apple Platform)
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Centralised application metadata and version information for the
 * iHymns Apple universal app. This file serves as the single point of
 * reference for all application identity, version, vendor, copyright,
 * and licensing information across all Apple platforms.
 *
 * STRUCTURE:
 * Mirrors the $app["Application"][...] array convention used in the
 * PHP web app (infoAppVer.php) and across all MWBM Partners Ltd
 * applications (e.g., DomainCheckr/phpWhoIs).
 *
 * USAGE:
 *   let appName = AppInfo.Application.name
 *   let version = AppInfo.Application.Version.number
 *   let copyright = AppInfo.Application.Copyright.full
 */

import Foundation

/* =========================================================================
 * APP INFO — CENTRALISED APPLICATION METADATA
 *
 * Structured as nested enums (namespaces) to mirror the PHP array hierarchy:
 *   AppInfo.Application.ID          → $app["Application"]["ID"]
 *   AppInfo.Application.Version.number → $app["Application"]["Version"]["Number"]
 *   etc.
 * ========================================================================= */

/// Central repository for all iHymns application metadata.
/// Access via `AppInfo.Application.name`, `AppInfo.Application.Version.number`, etc.
enum AppInfo {

    // MARK: - Application Identity

    /// Application identity, description, and website information.
    enum Application {

        /* Unique reverse-domain application identifier */
        static let id = "Ltd.MWBMPartners.iHymns.Apple"

        /* Short application name (used in titles, UI) */
        static let name = "iHymns"

        /* Application website URL */
        static let websiteURL = "https://ihymns.app"

        /// Application description and keywords.
        enum Description {

            /* Synopsis: brief description of the application's purpose */
            static let synopsis = "A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship."

            /* Keywords: comma-separated for discoverability */
            static let keywords = "hymns, worship, lyrics, songbook, Christian, church, praise, songs, offline, search, favourites"
        }

        // MARK: - Version Information

        /// Version numbers, development status, and build metadata.
        enum Version {

            /* Semantic version number (MAJOR.MINOR.PATCH) */
            /* v1.x.x = Phase 1 (local JSON data), v2.x.x = Phase 2 (iLyrics dB) */
            static let number = "1.0.0"

            /* Version name: human-readable release name (nil if unused) */
            static let name: String? = nil

            /// Development/build status and repository commit information.
            enum Development {

                /**
                 * Development status label.
                 *
                 * In production builds this should be nil.
                 * Set to "Alpha" or "Beta" for pre-release builds.
                 * For App Store builds: nil (production)
                 * For TestFlight builds: "Beta"
                 * For direct distribution dev builds: "Alpha"
                 */
                #if DEBUG
                static let status: String? = "Development"
                #else
                static let status: String? = nil
                #endif
            }

            /// Repository commit metadata (populated at build time via Xcode build scripts).
            enum Repo {
                enum Commit {
                    /* Full git commit SHA (40 characters) — nil until build-time injection */
                    static let shaFull: String? = nil

                    /* Short git commit SHA (7 characters) — nil until build-time injection */
                    static let shaShort: String? = nil

                    /* Commit date/time (ISO 8601) — nil until build-time injection */
                    static let date: String? = nil

                    /* GitHub URL to the specific commit — nil until build-time injection */
                    static let url: String? = nil
                }
            }

            /**
             * The version string from the app bundle's Info.plist.
             * This is the "marketing version" set in Xcode (CFBundleShortVersionString).
             * Falls back to the hardcoded `number` if bundle info is unavailable.
             */
            static var bundleVersion: String {
                Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? number
            }

            /**
             * The build number from the app bundle's Info.plist.
             * This is the "build version" set in Xcode (CFBundleVersion).
             */
            static var buildNumber: String {
                Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "1"
            }
        }

        // MARK: - Vendor Information

        /// Vendor (developer) and parent company details.
        enum Vendor {

            /* Primary vendor/developer name */
            static let name = "MWservices"

            /* Primary vendor website URL */
            static let websiteURL = "https://www.MWservices.it"

            /// Parent company information.
            enum Parent {

                /* Parent company name */
                static let name = "MWBM Partners Ltd"

                /* Parent company website URL */
                static let websiteURL = "https://www.MWBMpartners.Ltd"
            }
        }

        // MARK: - Copyright

        /// Copyright year range, rights statement, and full display string.
        enum Copyright {

            /* Year copyright protection began */
            static let yearStart = "2026"

            /**
             * Dynamically computed copyright year range for display.
             *
             * Returns "2026" if the current year matches the start year,
             * or "2026–<current year>" if later (e.g., "2026–2028").
             */
            static var yearDisplay: String {
                let currentYear = Calendar.current.component(.year, from: Date())
                let startYear = Int(yearStart) ?? 2026
                if currentYear > startYear {
                    return "\(startYear)–\(currentYear)"
                }
                return yearStart
            }

            /* Rights statement */
            static let rightsStatement = "All Rights Reserved"

            /**
             * Full copyright string for display.
             * Example: "© 2026 MWBM Partners Ltd. All Rights Reserved"
             */
            static var full: String {
                "© \(yearDisplay) \(Vendor.Parent.name). \(rightsStatement)"
            }
        }

        // MARK: - Licensing — Developer

        /// Developer licensing information.
        enum LicenseDeveloper {

            /* Developer licence type */
            static let type = "Proprietary"

            /* Developer licence cost */
            static let cost: String? = nil

            /* Developer licence agreement URL */
            static let agreementURL: String? = nil

            /* Developer terms of service URL */
            static let tosURL: String? = nil
        }

        // MARK: - Licensing — User / End-User

        /// End-user licensing information.
        enum LicenseUser {

            /* User licence type */
            static let type = "Freeware"

            /* User licence cost */
            static let cost = "Free"

            /* User licence agreement URL */
            static let agreementURL: String? = nil

            /* User terms of service URL */
            static let tosURL: String? = nil
        }

        // MARK: - Repository

        /// Source code repository information.
        enum Repo {

            /* GitHub repository URL */
            static let url = "https://github.com/MWBMPartners/iHymns"

            /* GitHub issues URL */
            static let issuesURL = "https://github.com/MWBMPartners/iHymns/issues"
        }
    }
}
