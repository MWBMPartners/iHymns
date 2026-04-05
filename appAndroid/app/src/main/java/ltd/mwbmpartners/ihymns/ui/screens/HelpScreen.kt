// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Help / About Screen
//
// PURPOSE:
// Displays application information, version details, and basic usage
// instructions. Information is sourced from AppInfo.kt to maintain a
// single source of truth for all application metadata.
//
// CONTENT:
// - Application name, version, and development status
// - Application description
// - Usage instructions for key features
// - Vendor and copyright information
// - Website links
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.screens

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ltd.mwbmpartners.ihymns.AppInfo

// =============================================================================
// HELP SCREEN COMPOSABLE
// =============================================================================

/**
 * Help and about screen displaying app information and usage instructions.
 *
 * All metadata (version, copyright, vendor) is sourced from [AppInfo] to
 * ensure consistency with other parts of the application.
 *
 * @param onBackClick Callback invoked when the back arrow is tapped.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HelpScreen(
    onBackClick: () -> Unit
) {
    Column(modifier = Modifier.fillMaxSize()) {
        // -----------------------------------------------------------------
        // TOP APP BAR
        // -----------------------------------------------------------------
        TopAppBar(
            title = {
                Text(
                    text = "Help & About",
                    fontWeight = FontWeight.Bold
                )
            },
            navigationIcon = {
                IconButton(onClick = onBackClick) {
                    Icon(
                        imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                        contentDescription = "Back"
                    )
                }
            },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.primaryContainer,
                titleContentColor = MaterialTheme.colorScheme.onPrimaryContainer
            )
        )

        // -----------------------------------------------------------------
        // SCROLLABLE CONTENT
        // -----------------------------------------------------------------
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(16.dp)
        ) {
            // =============================================================
            // APP IDENTITY
            // =============================================================
            Text(
                text = AppInfo.Application.NAME,
                style = MaterialTheme.typography.headlineLarge,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.primary
            )

            // Version and development status
            val versionText = buildString {
                append("Version ${AppInfo.Application.Version.NUMBER}")
                AppInfo.Application.Version.Development.STATUS?.let {
                    append(" ($it)")
                }
            }
            Text(
                text = versionText,
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Spacer(modifier = Modifier.height(16.dp))

            // =============================================================
            // DESCRIPTION
            // =============================================================
            Text(
                text = AppInfo.Application.Description.SYNOPSIS,
                style = MaterialTheme.typography.bodyLarge
            )

            Spacer(modifier = Modifier.height(24.dp))

            // =============================================================
            // HOW TO USE
            // =============================================================
            SectionTitle("How to Use")

            HelpItem(
                title = "Browse Songbooks",
                description = "Tap a songbook card on the home screen to view all songs in that collection."
            )

            HelpItem(
                title = "View Song Lyrics",
                description = "Tap any song in the list to view its full lyrics with verse and chorus formatting."
            )

            HelpItem(
                title = "Search",
                description = "Use the Search tab to find songs by title, number, or lyrics content across all songbooks."
            )

            HelpItem(
                title = "Favourites",
                description = "Tap the heart icon on any song to save it to your favourites. Swipe left on the Favourites screen to remove a song."
            )

            HelpItem(
                title = "Share",
                description = "Tap the share icon on any song detail screen to send the lyrics via messaging, email, or other apps."
            )

            Spacer(modifier = Modifier.height(24.dp))

            // =============================================================
            // ABOUT
            // =============================================================
            SectionTitle("About")

            Text(
                text = "Developed by ${AppInfo.Application.Vendor.NAME}",
                style = MaterialTheme.typography.bodyMedium
            )

            Text(
                text = AppInfo.Application.Vendor.Parent.NAME,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Spacer(modifier = Modifier.height(8.dp))

            Text(
                text = AppInfo.Application.WEBSITE_URL,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.primary
            )

            Spacer(modifier = Modifier.height(16.dp))

            // Copyright notice
            Text(
                text = AppInfo.Application.Copyright.full(),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            // Licence type
            Text(
                text = "Licence: ${AppInfo.Application.LicenseUser.TYPE} (${AppInfo.Application.LicenseUser.COST})",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            // Bottom spacing
            Spacer(modifier = Modifier.height(32.dp))
        }
    }
}

// =============================================================================
// HELPER COMPOSABLES
// =============================================================================

/**
 * Renders a section title with consistent styling.
 *
 * @param text The section title text.
 */
@Composable
private fun SectionTitle(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleLarge,
        fontWeight = FontWeight.SemiBold,
        modifier = Modifier.padding(bottom = 8.dp)
    )
}

/**
 * Renders a help item with a bold title and description paragraph.
 *
 * @param title The feature or action name.
 * @param description Explanation of how to use the feature.
 */
@Composable
private fun HelpItem(
    title: String,
    description: String
) {
    Column(modifier = Modifier.padding(bottom = 12.dp)) {
        Text(
            text = title,
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = FontWeight.SemiBold
        )
        Text(
            text = description,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}
