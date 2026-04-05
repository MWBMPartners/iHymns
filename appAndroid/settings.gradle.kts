// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Settings Configuration (Kotlin DSL)
//
// PURPOSE:
// Configures Gradle plugin management, dependency resolution repositories,
// and includes the app module in the build. This file is evaluated before
// any build.gradle.kts file and establishes where Gradle finds plugins
// and dependencies.
//
// REPOSITORY ORDER:
// 1. Google's Maven — Android SDK, AndroidX, Compose libraries
// 2. Maven Central — Kotlin, KotlinX, community libraries
// 3. Gradle Plugin Portal — Gradle plugins not hosted elsewhere
//
// NOTE:
// No Google Play Services or Firebase repositories are included to ensure
// full compatibility with Amazon Fire OS devices.
// =============================================================================

pluginManagement {
    // -------------------------------------------------------------------------
    // Plugin Repositories — where Gradle resolves plugin dependencies
    // -------------------------------------------------------------------------
    repositories {
        // Google's Maven repository: Android Gradle Plugin, AndroidX, Compose
        google {
            content {
                // Only resolve Google-group artifacts from this repository
                includeGroupByRegex("com\\.android.*")
                includeGroupByRegex("com\\.google.*")
                includeGroupByRegex("androidx.*")
            }
        }

        // Maven Central: Kotlin compiler, KotlinX libraries, third-party libs
        mavenCentral()

        // Gradle Plugin Portal: fallback for community Gradle plugins
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    // -------------------------------------------------------------------------
    // Dependency Resolution Strategy
    //
    // FAIL_ON_PROJECT_REPOS ensures all repositories are declared here (in
    // settings) rather than scattered across individual module build files.
    // This centralises dependency resolution and prevents version conflicts.
    // -------------------------------------------------------------------------
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)

    repositories {
        // Google's Maven repository for AndroidX and Compose artifacts
        google()

        // Maven Central for Kotlin, serialization, and community libraries
        mavenCentral()
    }
}

// =============================================================================
// PROJECT CONFIGURATION
// =============================================================================

// Root project name — appears in Gradle logs and IDE project view
rootProject.name = "iHymns"

// Include the main application module
include(":app")
