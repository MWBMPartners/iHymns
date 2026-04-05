// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Main Activity (Single-Activity Architecture)
//
// PURPOSE:
// Entry point for the iHymns Android application. This is the sole Activity
// in the app — all screen navigation is handled internally by Jetpack Compose
// Navigation (NavHost). The activity sets up the Compose UI tree, applies the
// application theme, and configures edge-to-edge display.
//
// ARCHITECTURE:
// iHymns uses a single-activity architecture with Jetpack Compose:
//   MainActivity (this file)
//     └── iHymnsTheme (Theme.kt)
//           └── iHymnsApp (Navigation.kt — NavHost with all screen routes)
//
// PLATFORM SUPPORT:
// This activity serves all target platforms:
//   - Android phones/tablets: standard touch-based interaction
//   - Amazon Fire OS: identical to phone/tablet (no Play Services required)
//   - Android TV / Fire TV: D-pad navigation via Compose focus system
//   - ChromeOS: runs within the Android container with keyboard/mouse support
// =============================================================================

package ltd.mwbmpartners.ihymns

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import ltd.mwbmpartners.ihymns.ui.iHymnsApp
import ltd.mwbmpartners.ihymns.ui.theme.iHymnsTheme

/**
 * Main entry point activity for the iHymns application.
 *
 * This activity:
 * 1. Enables edge-to-edge rendering (content draws behind system bars)
 * 2. Applies the iHymns Material3 theme
 * 3. Launches the Compose-based navigation graph via [iHymnsApp]
 *
 * All subsequent navigation (home, songbook, song detail, search, favourites,
 * help) is handled by the Compose NavHost — no additional activities are needed.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Enable edge-to-edge display: app content renders behind the status
        // bar and navigation bar for a modern, immersive appearance. System bar
        // colours are handled by the Compose theme.
        enableEdgeToEdge()

        // Set the Compose UI content tree. This replaces the traditional
        // setContentView(R.layout.activity_main) approach used with XML layouts.
        setContent {
            // Apply the iHymns Material3 theme (colours, typography, shapes)
            iHymnsTheme {
                // Launch the top-level composable that contains the NavHost
                // and all screen navigation routes
                iHymnsApp()
            }
        }
    }
}
