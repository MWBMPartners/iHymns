// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Root / Project-Level Build Configuration (Kotlin DSL)
//
// PURPOSE:
// Defines the top-level Gradle build configuration for the iHymns Android
// project. This file declares the Gradle plugins used across all modules
// (application, library, Kotlin, Compose compiler, serialization) and their
// versions. Individual module configurations reside in their own
// build.gradle.kts files.
//
// COMPATIBILITY:
// - Android Gradle Plugin 8.x (AGP 8.7.x)
// - Kotlin 2.1.x with Compose Compiler Plugin
// - Targets: Android phones/tablets, Amazon Fire OS, Android TV, ChromeOS
//
// NOTE:
// No Google Play Services plugins are declared here to maintain full
// compatibility with Amazon Fire OS devices (Fire tablets, Fire TV,
// Fire TV Stick) which do not ship with Google Play Services.
// =============================================================================

plugins {
    // -------------------------------------------------------------------------
    // Android Application Plugin — provides the 'android' block for app modules
    // Version must match the AGP version declared in settings.gradle.kts
    // -------------------------------------------------------------------------
    id("com.android.application") version "8.7.3" apply false

    // -------------------------------------------------------------------------
    // Kotlin Android Plugin — enables Kotlin compilation for Android targets
    // Version 2.1.x provides K2 compiler support and improved build performance
    // -------------------------------------------------------------------------
    id("org.jetbrains.kotlin.android") version "2.1.0" apply false

    // -------------------------------------------------------------------------
    // Kotlin Compose Compiler Plugin — required for Jetpack Compose in Kotlin 2.0+
    // Replaces the old compose compiler version pinning; now a standalone plugin
    // that automatically matches the Kotlin version
    // -------------------------------------------------------------------------
    id("org.jetbrains.kotlin.plugin.compose") version "2.1.0" apply false

    // -------------------------------------------------------------------------
    // Kotlinx Serialization Plugin — enables @Serializable annotations and
    // compile-time code generation for JSON parsing of songs.json data
    // -------------------------------------------------------------------------
    id("org.jetbrains.kotlin.plugin.serialization") version "2.1.0" apply false
}
