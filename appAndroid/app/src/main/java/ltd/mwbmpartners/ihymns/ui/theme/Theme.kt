// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Material3 Theme Configuration
//
// PURPOSE:
// Defines the Material3 colour scheme and theme for the iHymns Android app.
// Uses an amber-based colour palette that matches the iLyrics dB brand
// identity across all platforms (web, Apple, Android).
//
// COLOUR PALETTE:
// The theme is built around warm amber/golden tones:
//   Primary:   Amber (#FFA000) — used for interactive elements, headers
//   Secondary: Deep Orange (#FF6F00) — used for accents, secondary actions
//   Tertiary:  Brown (#795548) — used for subtle accents
//
// Both light and dark colour schemes are defined. The app automatically
// switches based on the system theme setting on all target platforms
// (phones, Fire OS, Android TV, ChromeOS).
//
// DESIGN CONSISTENCY:
// The amber colour scheme is consistent with:
//   - The iLyrics dB web application
//   - The iHymns Apple (iOS/iPadOS/macOS) app
//   - The songbook card gradient colours used in HomeScreen.kt
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext

// =============================================================================
// COLOUR DEFINITIONS — Amber Brand Palette
//
// These colour values define the iHymns brand palette. They are used to
// construct both the light and dark Material3 colour schemes below.
// =============================================================================

// Primary colours — Amber family
/** Primary amber colour — main brand colour for interactive elements */
private val Amber = Color(0xFFFFA000)

/** Dark amber — used in dark theme as primary colour */
private val AmberDark = Color(0xFFFFB300)

/** Light amber — used for primary containers in light theme */
private val AmberLight = Color(0xFFFFECB3)

// Secondary colours — Deep orange family
/** Secondary deep orange — accent colour for secondary actions */
private val DeepOrange = Color(0xFFFF6F00)

/** Dark deep orange — used in dark theme as secondary colour */
private val DeepOrangeDark = Color(0xFFFF8F00)

/** Light deep orange — used for secondary containers */
private val DeepOrangeLight = Color(0xFFFFE0B2)

// Tertiary colours — Brown family
/** Tertiary brown — subtle accent colour */
private val Brown = Color(0xFF795548)

/** Light brown — used for tertiary containers */
private val BrownLight = Color(0xFFD7CCC8)

// Surface and background colours
/** Dark surface colour for dark theme backgrounds */
private val DarkSurface = Color(0xFF1C1B1F)

/** Dark background colour */
private val DarkBackground = Color(0xFF1C1B1F)

// =============================================================================
// LIGHT COLOUR SCHEME
//
// Used when the system is in light mode (default on most devices).
// Amber primary with warm surface tones.
// =============================================================================
private val LightColorScheme = lightColorScheme(
    primary = Amber,
    onPrimary = Color.White,
    primaryContainer = AmberLight,
    onPrimaryContainer = Color(0xFF3E2723),
    secondary = DeepOrange,
    onSecondary = Color.White,
    secondaryContainer = DeepOrangeLight,
    onSecondaryContainer = Color(0xFF3E2723),
    tertiary = Brown,
    onTertiary = Color.White,
    tertiaryContainer = BrownLight,
    onTertiaryContainer = Color(0xFF3E2723),
    background = Color(0xFFFFFBFE),
    onBackground = Color(0xFF1C1B1F),
    surface = Color(0xFFFFFBFE),
    onSurface = Color(0xFF1C1B1F),
    surfaceVariant = Color(0xFFF5F0EB),
    onSurfaceVariant = Color(0xFF49454F),
    outline = Color(0xFF79747E),
    outlineVariant = Color(0xFFCAC4D0)
)

// =============================================================================
// DARK COLOUR SCHEME
//
// Used when the system is in dark mode. Lighter amber tones on dark
// surfaces for readability and reduced eye strain.
// =============================================================================
private val DarkColorScheme = darkColorScheme(
    primary = AmberDark,
    onPrimary = Color(0xFF3E2723),
    primaryContainer = Color(0xFF5D4037),
    onPrimaryContainer = AmberLight,
    secondary = DeepOrangeDark,
    onSecondary = Color(0xFF3E2723),
    secondaryContainer = Color(0xFF4E342E),
    onSecondaryContainer = DeepOrangeLight,
    tertiary = BrownLight,
    onTertiary = Color(0xFF3E2723),
    tertiaryContainer = Color(0xFF4E342E),
    onTertiaryContainer = BrownLight,
    background = DarkBackground,
    onBackground = Color(0xFFE6E1E5),
    surface = DarkSurface,
    onSurface = Color(0xFFE6E1E5),
    surfaceVariant = Color(0xFF2C2C2E),
    onSurfaceVariant = Color(0xFFCAC4D0),
    outline = Color(0xFF938F99),
    outlineVariant = Color(0xFF49454F)
)

// =============================================================================
// THEME COMPOSABLE
// =============================================================================

/**
 * iHymns Material3 theme wrapper.
 *
 * Applies the appropriate colour scheme based on system dark mode setting.
 * On Android 12+ (API 31+), dynamic colour is available but we use our
 * custom amber scheme to maintain brand consistency across all platforms
 * including Amazon Fire OS which does not support dynamic colour.
 *
 * @param darkTheme Whether to use the dark colour scheme. Defaults to the
 *                  system setting via [isSystemInDarkTheme].
 * @param content The composable content tree to wrap with the theme.
 */
@Composable
fun iHymnsTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    // Select the colour scheme based on dark mode setting.
    //
    // NOTE: We intentionally do NOT use dynamic colours (Material You) even
    // on Android 12+ devices. The iHymns amber brand colours must remain
    // consistent across all platforms, including Amazon Fire OS devices
    // which run older Android versions without dynamic colour support.
    val colorScheme = if (darkTheme) {
        DarkColorScheme
    } else {
        LightColorScheme
    }

    // Apply the Material3 theme with our custom colour scheme
    MaterialTheme(
        colorScheme = colorScheme,
        content = content
    )
}
