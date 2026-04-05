/**
 * iHymns — Application Version & Information (Android Platform)
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * PURPOSE:
 * Centralised application metadata and version information for the
 * iHymns Android app. This file serves as the single point of reference
 * for all application identity, version, vendor, copyright, and licensing
 * information across all Android platforms (phones, tablets, Fire OS,
 * Android TV, ChromeOS).
 *
 * STRUCTURE:
 * Mirrors the $app["Application"][...] array convention used in the
 * PHP web app (infoAppVer.php) and the Apple app (AppInfo.swift),
 * consistent across all MWBM Partners Ltd applications.
 *
 * USAGE:
 *   val appName = AppInfo.Application.NAME
 *   val version = AppInfo.Application.Version.NUMBER
 *   val copyright = AppInfo.Application.Copyright.full()
 */

package ltd.mwbmpartners.ihymns

import java.util.Calendar

/* =========================================================================
 * APP INFO — CENTRALISED APPLICATION METADATA
 *
 * Structured as nested objects to mirror the PHP array / Swift enum hierarchy:
 *   AppInfo.Application.ID           → $app["Application"]["ID"]
 *   AppInfo.Application.Version.NUMBER → $app["Application"]["Version"]["Number"]
 *   etc.
 * ========================================================================= */

/**
 * Central repository for all iHymns application metadata.
 *
 * Access via `AppInfo.Application.NAME`, `AppInfo.Application.Version.NUMBER`, etc.
 */
object AppInfo {

    // =========================================================================
    // APPLICATION IDENTITY
    // =========================================================================

    /**
     * Application identity, description, and website information.
     */
    object Application {

        /* Unique reverse-domain application identifier */
        const val ID = "Ltd.MWBMPartners.iHymns.Android"

        /* Short application name (used in titles, UI) */
        const val NAME = "iHymns"

        /* Application website URL */
        const val WEBSITE_URL = "https://ihymns.app"

        /**
         * Application description and keywords.
         */
        object Description {

            /* Synopsis: brief description of the application's purpose */
            const val SYNOPSIS = "A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship."

            /* Keywords: comma-separated for discoverability */
            const val KEYWORDS = "hymns, worship, lyrics, songbook, Christian, church, praise, songs, offline, search, favourites"
        }

        // =====================================================================
        // VERSION INFORMATION
        // =====================================================================

        /**
         * Version numbers, development status, and build metadata.
         */
        object Version {

            /* Semantic version number (MAJOR.MINOR.PATCH) */
            /* v1.x.x = Phase 1 (local JSON data), v2.x.x = Phase 2 (iLyrics dB) */
            const val NUMBER = "1.0.0"

            /* Version name: human-readable release name (null if unused) */
            val NAME: String? = null

            /**
             * Development/build status and repository commit information.
             */
            object Development {

                /**
                 * Development status label.
                 *
                 * In production builds this should be null.
                 * Set to "Alpha" or "Beta" for pre-release builds.
                 * BuildConfig.DEBUG can be used to auto-detect.
                 */
                val STATUS: String? = if (BuildConfig.DEBUG) "Development" else null
            }

            /**
             * Repository commit metadata.
             *
             * These values are populated at build time via Gradle build scripts
             * or GitHub Actions. They default to null in source.
             */
            object Repo {
                object Commit {
                    /* Full git commit SHA (40 characters) — null until build-time injection */
                    val SHA_FULL: String? = null

                    /* Short git commit SHA (7 characters) — null until build-time injection */
                    val SHA_SHORT: String? = null

                    /* Commit date/time (ISO 8601) — null until build-time injection */
                    val DATE: String? = null

                    /* GitHub URL to the specific commit — null until build-time injection */
                    val URL: String? = null
                }
            }
        }

        // =====================================================================
        // VENDOR INFORMATION
        // =====================================================================

        /**
         * Vendor (developer) and parent company details.
         */
        object Vendor {

            /* Primary vendor/developer name */
            const val NAME = "MWservices"

            /* Primary vendor website URL */
            const val WEBSITE_URL = "https://www.MWservices.it"

            /**
             * Parent company information.
             */
            object Parent {

                /* Parent company name */
                const val NAME = "MWBM Partners Ltd"

                /* Parent company website URL */
                const val WEBSITE_URL = "https://www.MWBMpartners.Ltd"
            }
        }

        // =====================================================================
        // COPYRIGHT
        // =====================================================================

        /**
         * Copyright year range, rights statement, and full display string.
         */
        object Copyright {

            /* Year copyright protection began */
            const val YEAR_START = "2026"

            /* Rights statement */
            const val RIGHTS_STATEMENT = "All Rights Reserved"

            /**
             * Dynamically computed copyright year range for display.
             *
             * Returns "2026" if the current year matches the start year,
             * or "2026–<current year>" if later (e.g., "2026–2028").
             */
            fun yearDisplay(): String {
                val currentYear = Calendar.getInstance().get(Calendar.YEAR)
                val startYear = YEAR_START.toIntOrNull() ?: 2026
                return if (currentYear > startYear) {
                    "$startYear–$currentYear"
                } else {
                    YEAR_START
                }
            }

            /**
             * Full copyright string for display.
             *
             * Example: "© 2026 MWBM Partners Ltd. All Rights Reserved"
             */
            fun full(): String {
                return "© ${yearDisplay()} ${Vendor.Parent.NAME}. $RIGHTS_STATEMENT"
            }
        }

        // =====================================================================
        // LICENSING — DEVELOPER
        // =====================================================================

        /**
         * Developer licensing information.
         */
        object LicenseDeveloper {

            /* Developer licence type */
            const val TYPE = "Proprietary"

            /* Developer licence cost */
            val COST: String? = null

            /* Developer licence agreement URL */
            val AGREEMENT_URL: String? = null

            /* Developer terms of service URL */
            val TOS_URL: String? = null
        }

        // =====================================================================
        // LICENSING — USER / END-USER
        // =====================================================================

        /**
         * End-user licensing information.
         */
        object LicenseUser {

            /* User licence type */
            const val TYPE = "Freeware"

            /* User licence cost */
            const val COST = "Free"

            /* User licence agreement URL */
            val AGREEMENT_URL: String? = null

            /* User terms of service URL */
            val TOS_URL: String? = null
        }

        // =====================================================================
        // REPOSITORY
        // =====================================================================

        /**
         * Source code repository information.
         */
        object Repo {

            /* GitHub repository URL */
            const val URL = "https://github.com/MWBMPartners/iHymns"

            /* GitHub issues URL */
            const val ISSUES_URL = "https://github.com/MWBMPartners/iHymns/issues"
        }
    }
}
