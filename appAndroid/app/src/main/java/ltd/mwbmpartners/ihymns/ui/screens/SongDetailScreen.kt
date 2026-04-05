// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Song Detail Screen (Full Lyrics Display)
//
// PURPOSE:
// Displays the full lyrics of a selected song with proper formatting for
// verses, choruses, bridges, and other lyric components. Provides actions
// to toggle the song as a favourite and to share the lyrics via the
// Android share sheet.
//
// LAYOUT:
// - Top app bar with song title, back navigation, favourite toggle, share
// - Scrollable content area with:
//   - Song metadata (number, songbook, writers, composers)
//   - Structured lyrics with labelled sections (Verse 1, Chorus, etc.)
//   - Copyright notice at the bottom
//
// FORMATTING:
// - Verses: prefixed with "Verse N" label, normal weight text
// - Choruses: prefixed with "Chorus" label, italic text for distinction
// - Bridges: prefixed with "Bridge" label
// - Each component is visually separated with spacing
// - Lyric lines preserve their original line breaks from songs.json
//
// SHARE FUNCTIONALITY:
// Uses Android's ACTION_SEND intent to share lyrics as plain text.
// This is a platform-agnostic approach that works on all target devices
// (phones, Fire OS, TV, ChromeOS) without Google Play Services.
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.screens

import android.content.Intent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.FavoriteBorder
import androidx.compose.material.icons.filled.Share
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import ltd.mwbmpartners.ihymns.models.Song
import ltd.mwbmpartners.ihymns.models.SongComponent
import ltd.mwbmpartners.ihymns.viewmodel.SongViewModel

// =============================================================================
// SONG DETAIL SCREEN COMPOSABLE
// =============================================================================

/**
 * Displays the full lyrics and metadata for a single song.
 *
 * @param viewModel Shared [SongViewModel] for song data and favourite state.
 * @param songId The unique ID of the song to display (e.g., "CP-0001").
 * @param onBackClick Callback invoked when the back arrow is tapped.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SongDetailScreen(
    viewModel: SongViewModel,
    songId: String,
    onBackClick: () -> Unit
) {
    // Retrieve the song by ID
    val song = viewModel.getSongById(songId)

    // Observe favourite state reactively so the heart icon updates immediately
    val favouriteIds by viewModel.favouriteIds.collectAsState()
    val isFavourite = songId in favouriteIds

    // Context for launching the share intent
    val context = LocalContext.current

    Column(modifier = Modifier.fillMaxSize()) {
        // -----------------------------------------------------------------
        // TOP APP BAR — Song title, back, favourite toggle, share
        // -----------------------------------------------------------------
        TopAppBar(
            title = {
                Text(
                    text = song?.title ?: "Song",
                    fontWeight = FontWeight.Bold,
                    maxLines = 1,
                    overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis
                )
            },
            navigationIcon = {
                // Back arrow — returns to the song list or previous screen
                IconButton(onClick = onBackClick) {
                    Icon(
                        imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                        contentDescription = "Back"
                    )
                }
            },
            actions = {
                // Favourite toggle button — filled/outlined heart icon
                IconButton(onClick = { viewModel.toggleFavourite(songId) }) {
                    Icon(
                        imageVector = if (isFavourite) {
                            Icons.Default.Favorite
                        } else {
                            Icons.Default.FavoriteBorder
                        },
                        contentDescription = if (isFavourite) {
                            "Remove from favourites"
                        } else {
                            "Add to favourites"
                        },
                        tint = if (isFavourite) Color.Red else MaterialTheme.colorScheme.onSurface
                    )
                }

                // Share button — opens the Android share sheet with lyrics text
                IconButton(onClick = {
                    song?.let { shareSong(context, it) }
                }) {
                    Icon(
                        imageVector = Icons.Default.Share,
                        contentDescription = "Share song"
                    )
                }
            },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.primaryContainer,
                titleContentColor = MaterialTheme.colorScheme.onPrimaryContainer
            )
        )

        // -----------------------------------------------------------------
        // SONG CONTENT — Metadata and lyrics
        // -----------------------------------------------------------------
        if (song != null) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(rememberScrollState())
                    .padding(16.dp)
            ) {
                // Song metadata header
                SongMetadataSection(song = song)

                Spacer(modifier = Modifier.height(16.dp))
                HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                Spacer(modifier = Modifier.height(16.dp))

                // Lyrics — each component (verse, chorus, etc.) as a section
                song.components.forEachIndexed { index, component ->
                    LyricComponentSection(component = component)
                    if (index < song.components.lastIndex) {
                        Spacer(modifier = Modifier.height(16.dp))
                    }
                }

                // Copyright notice at the bottom of the lyrics
                if (song.copyright.isNotBlank()) {
                    Spacer(modifier = Modifier.height(24.dp))
                    HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                    Spacer(modifier = Modifier.height(8.dp))
                    Text(
                        text = song.copyright,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        fontStyle = FontStyle.Italic
                    )
                }

                // Bottom spacing for comfortable scrolling
                Spacer(modifier = Modifier.height(32.dp))
            }
        } else {
            // Song not found — should not happen in normal usage
            Text(
                text = "Song not found.",
                style = MaterialTheme.typography.bodyLarge,
                modifier = Modifier.padding(16.dp)
            )
        }
    }
}

// =============================================================================
// SONG METADATA SECTION
// =============================================================================

/**
 * Displays the song's metadata: number, songbook, writers, and composers.
 *
 * @param song The song whose metadata to display.
 */
@Composable
private fun SongMetadataSection(song: Song) {
    // Song number and songbook name
    Text(
        text = "${song.songbookName} #${song.number}",
        style = MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.SemiBold,
        color = MaterialTheme.colorScheme.primary
    )

    // Writers (lyricists)
    if (song.writers.isNotEmpty()) {
        Spacer(modifier = Modifier.height(4.dp))
        Text(
            text = buildAnnotatedString {
                withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) {
                    append("Words: ")
                }
                append(song.writers.joinToString(", "))
            },
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }

    // Composers
    if (song.composers.isNotEmpty()) {
        Spacer(modifier = Modifier.height(2.dp))
        Text(
            text = buildAnnotatedString {
                withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) {
                    append("Music: ")
                }
                append(song.composers.joinToString(", "))
            },
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }

    // CCLI number if available
    if (song.ccli.isNotBlank()) {
        Spacer(modifier = Modifier.height(2.dp))
        Text(
            text = "CCLI: ${song.ccli}",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}

// =============================================================================
// LYRIC COMPONENT SECTION (Verse, Chorus, Bridge, etc.)
// =============================================================================

/**
 * Renders a single lyric component (verse, chorus, bridge, etc.) with
 * appropriate label and text styling.
 *
 * - Verses: "Verse N" label, normal weight
 * - Choruses: "Chorus" label, italic text for visual distinction
 * - Bridges: "Bridge" label, normal weight
 * - Other types: capitalised type label, normal weight
 *
 * @param component The lyric component to render.
 */
@Composable
private fun LyricComponentSection(component: SongComponent) {
    // Determine the section label based on component type and number
    val label = when (component.type.lowercase()) {
        "verse" -> "Verse ${component.number ?: ""}"
        "chorus" -> "Chorus"
        "bridge" -> "Bridge"
        "pre-chorus" -> "Pre-Chorus"
        "intro" -> "Intro"
        "outro" -> "Outro"
        "tag" -> "Tag"
        else -> component.type.replaceFirstChar { it.uppercase() }
    }

    // Determine if the text should be italicised (choruses are italicised
    // for visual distinction from verses)
    val isChorus = component.type.lowercase() == "chorus"

    // Section label (e.g., "Verse 1", "Chorus")
    Text(
        text = label.trim(),
        style = MaterialTheme.typography.labelLarge,
        fontWeight = FontWeight.Bold,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(bottom = 4.dp)
    )

    // Lyric lines — joined with line breaks, styled based on component type
    Text(
        text = component.lines.joinToString("\n"),
        style = MaterialTheme.typography.bodyLarge,
        fontStyle = if (isChorus) FontStyle.Italic else FontStyle.Normal,
        lineHeight = MaterialTheme.typography.bodyLarge.lineHeight,
        modifier = Modifier.fillMaxWidth()
    )
}

// =============================================================================
// SHARE FUNCTIONALITY
// =============================================================================

/**
 * Launches the Android share sheet with the song's lyrics as plain text.
 *
 * The shared text includes:
 * - Song title and songbook reference
 * - All lyric components with section labels
 * - Copyright notice
 * - App attribution
 *
 * Uses [Intent.ACTION_SEND] which is supported on all target platforms
 * (phones, Fire OS, Android TV, ChromeOS) without Google Play Services.
 *
 * @param context Android context for starting the share activity.
 * @param song The song whose lyrics to share.
 */
private fun shareSong(context: android.content.Context, song: Song) {
    // Build the plain-text representation of the song
    val text = buildString {
        // Title and songbook reference
        appendLine("${song.title}")
        appendLine("${song.songbookName} #${song.number}")
        appendLine()

        // Lyric components with labels
        song.components.forEach { component ->
            val label = when (component.type.lowercase()) {
                "verse" -> "Verse ${component.number ?: ""}"
                "chorus" -> "Chorus"
                "bridge" -> "Bridge"
                else -> component.type.replaceFirstChar { it.uppercase() }
            }
            appendLine(label.trim())
            component.lines.forEach { line -> appendLine(line) }
            appendLine()
        }

        // Copyright and attribution
        if (song.copyright.isNotBlank()) {
            appendLine(song.copyright)
            appendLine()
        }
        append("Shared from iHymns — ihymns.app")
    }

    // Create and launch the share intent
    val sendIntent = Intent().apply {
        action = Intent.ACTION_SEND
        putExtra(Intent.EXTRA_TEXT, text)
        putExtra(Intent.EXTRA_SUBJECT, song.title)
        type = "text/plain"
    }

    // Wrap in a chooser for consistent behaviour across devices
    val shareIntent = Intent.createChooser(sendIntent, "Share \"${song.title}\"")
    context.startActivity(shareIntent)
}
