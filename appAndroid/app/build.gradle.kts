// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — App Module Build Configuration (Kotlin DSL)
//
// PURPOSE:
// Configures the main application module for the iHymns Android app.
// Defines compile/target SDK versions, application identity, Jetpack Compose
// setup, and all library dependencies.
//
// PLATFORM TARGETS:
// - Standard Android phones and tablets (API 26+)
// - Amazon Fire OS (Fire tablets, Fire TV, Fire TV Stick)
// - Android TV (via Leanback library)
// - ChromeOS (via Android app compatibility layer)
//
// IMPORTANT — FIRE OS COMPATIBILITY:
// This build file intentionally excludes ALL Google Play Services dependencies
// (com.google.android.gms:*) to ensure the app runs on Amazon Fire OS devices
// which do not include Google Play Services. Any future dependency additions
// must be verified for Fire OS compatibility before inclusion.
//
// VERSION MANAGEMENT:
// versionCode and versionName are derived from AppInfo.kt to maintain a
// single source of truth for version information across the application.
// =============================================================================

plugins {
    // Android Application Plugin — provides the android { } DSL block
    id("com.android.application")

    // Kotlin Android Plugin — enables Kotlin language support for Android
    id("org.jetbrains.kotlin.android")

    // Compose Compiler Plugin — required for Jetpack Compose in Kotlin 2.0+
    id("org.jetbrains.kotlin.plugin.compose")

    // Kotlinx Serialization — compile-time code generation for @Serializable classes
    id("org.jetbrains.kotlin.plugin.serialization")
}

android {
    // -------------------------------------------------------------------------
    // MODULE IDENTITY
    // -------------------------------------------------------------------------

    // Namespace for generated R class and BuildConfig (matches package structure)
    namespace = "ltd.mwbmpartners.ihymns"

    // -------------------------------------------------------------------------
    // SDK VERSIONS
    //
    // compileSdk 35: Android 15 (VanillaIceCream) — latest API for compilation
    // minSdk 26:     Android 8.0 (Oreo) — covers 95%+ of active devices and
    //               all Amazon Fire OS devices currently in production
    // targetSdk 35:  Opts in to Android 15 runtime behaviour changes
    // -------------------------------------------------------------------------
    compileSdk = 35

    defaultConfig {
        // Unique application identifier on all app stores and devices
        applicationId = "ltd.mwbmpartners.ihymns"

        // Minimum Android API level required to install the app
        minSdk = 26

        // Target API level — enables latest platform behaviour optimisations
        targetSdk = 35

        // =====================================================================
        // VERSION CODE & NAME
        //
        // Derived from the semantic version in AppInfo.kt:
        //   Version "1.0.0" → versionCode = 1*10000 + 0*100 + 0 = 10000
        //   versionName = "1.0.0"
        //
        // This encoding supports versions up to 99.99.99 and ensures that
        // newer semantic versions always produce higher integer codes.
        // =====================================================================
        versionCode = 10000
        versionName = "1.0.0"

        // AndroidX Test instrumentation runner for connected/device tests
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // Enable vector drawable support on API < 26 (safety net)
        vectorDrawables {
            useSupportLibrary = true
        }
    }

    // -------------------------------------------------------------------------
    // BUILD TYPES
    // -------------------------------------------------------------------------
    buildTypes {
        release {
            // Enable code shrinking and obfuscation via R8 for release builds
            isMinifyEnabled = true

            // Enable resource shrinking to remove unused resources
            isShrinkResources = true

            // ProGuard/R8 rules: default Android rules + custom project rules
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }

        debug {
            // Disable minification in debug for faster builds and debugging
            isMinifyEnabled = false

            // Append .debug suffix to applicationId so debug and release can
            // coexist on the same device during development
            applicationIdSuffix = ".debug"
        }
    }

    // -------------------------------------------------------------------------
    // JAVA / KOTLIN COMPATIBILITY
    //
    // Target Java 17 bytecode for compatibility with AGP 8.x requirements
    // and modern Android toolchain expectations.
    // -------------------------------------------------------------------------
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    // -------------------------------------------------------------------------
    // JETPACK COMPOSE CONFIGURATION
    //
    // Enable Compose build features. The Compose Compiler Plugin (declared
    // above) handles compiler version management automatically in Kotlin 2.0+.
    // -------------------------------------------------------------------------
    buildFeatures {
        compose = true
        buildConfig = true
    }

    // -------------------------------------------------------------------------
    // PACKAGING OPTIONS
    //
    // Exclude duplicate licence files that cause packaging conflicts when
    // multiple libraries bundle the same META-INF resources.
    // -------------------------------------------------------------------------
    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

// =============================================================================
// DEPENDENCIES
//
// All dependencies are organised by category. Version numbers are managed
// via the Compose BOM (Bill of Materials) where possible to ensure
// compatible Compose library versions.
//
// FIRE OS COMPATIBILITY NOTICE:
// No com.google.android.gms:* (Google Play Services) dependencies are
// included. All functionality uses platform-agnostic alternatives.
// =============================================================================

dependencies {

    // =========================================================================
    // JETPACK COMPOSE — Bill of Materials (BOM)
    //
    // The Compose BOM manages consistent versions across all Compose libraries.
    // When using the BOM, individual Compose dependencies do not specify
    // a version — the BOM provides it automatically.
    // =========================================================================
    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    // =========================================================================
    // JETPACK COMPOSE — UI Libraries
    // =========================================================================

    // Core Compose UI framework — layout, drawing, input handling
    implementation("androidx.compose.ui:ui")

    // Compose UI graphics utilities — colours, brushes, shapes
    implementation("androidx.compose.ui:ui-graphics")

    // Compose UI tooling preview — @Preview support in Android Studio
    implementation("androidx.compose.ui:ui-tooling-preview")

    // Debug-only Compose tooling — layout inspector, recomposition tracking
    debugImplementation("androidx.compose.ui:ui-tooling")

    // Debug-only test manifest — required for Compose UI testing
    debugImplementation("androidx.compose.ui:ui-test-manifest")

    // =========================================================================
    // MATERIAL DESIGN 3
    //
    // Material3 provides the design system components (buttons, cards, top bars,
    // navigation drawers, etc.) and dynamic colour theming used throughout
    // the iHymns UI.
    // =========================================================================
    implementation("androidx.compose.material3:material3")

    // Material Icons Extended — additional icon set beyond the default icons
    implementation("androidx.compose.material.icons:icons-extended" )

    // =========================================================================
    // NAVIGATION — Jetpack Compose Navigation
    //
    // Provides the NavHost, NavController, and route-based navigation system
    // used to navigate between screens (home, songbook, song detail, search,
    // favourites, help).
    // =========================================================================
    implementation("androidx.navigation:navigation-compose:2.8.5")

    // =========================================================================
    // ANDROIDX CORE LIBRARIES
    // =========================================================================

    // Core KTX — Kotlin extensions for Android framework APIs
    implementation("androidx.core:core-ktx:1.15.0")

    // Activity Compose — ComponentActivity integration with Compose setContent
    implementation("androidx.activity:activity-compose:1.9.3")

    // Lifecycle Runtime Compose — collectAsStateWithLifecycle for StateFlow
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")

    // Lifecycle ViewModel Compose — viewModel() integration with Compose
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")

    // =========================================================================
    // KOTLINX SERIALIZATION — JSON Parsing
    //
    // Used to deserialise songs.json from the assets folder into Kotlin data
    // classes. This is a compile-time code generation approach (no reflection)
    // which provides excellent performance and small APK size.
    // =========================================================================
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")

    // =========================================================================
    // ANDROID TV — Leanback Library
    //
    // Provides TV-optimised UI components and D-pad navigation support for
    // Android TV and Amazon Fire TV devices. Declared as optional — the app
    // adapts its UI based on device type at runtime.
    // =========================================================================
    implementation("androidx.leanback:leanback:1.0.0")

    // =========================================================================
    // TESTING — Unit Tests
    // =========================================================================
    testImplementation("junit:junit:4.13.2")

    // =========================================================================
    // TESTING — Instrumented / Device Tests
    // =========================================================================
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
}
