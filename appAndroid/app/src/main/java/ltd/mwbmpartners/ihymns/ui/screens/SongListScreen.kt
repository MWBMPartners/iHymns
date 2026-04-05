// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Song List Screen
//
// PURPOSE:
// Displays a scrollable list of all songs within a selected songbook.
// Each list item shows the song number and title. Tapping a song navigates
// to the full lyrics detail view.
//
// LAYOUT:
// - Top app bar with songbook name and back navigation arrow
// - LazyColumn of song items for efficient scrolling (supports 1,000+ songs)
// - Each item shows: song number (formatted), song title
// - Dividers between items for visual separation
//
// PERFORMANCE:
// LazyColumn only composes items visible on screen plus a small buffer,
// making it efficient even for the largest songbook (Mission Praise with
// 1,355 songs). Items are keyed by song ID for stable recomposition.
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ltd.mwbmpartners.ihymns.models.Song
import ltd.mwbmpartners.ihymns.viewmodel.SongViewModel

// =============================================================================
// SONG LIST SCREEN COMPOSABLE
// =============================================================================

/**
 * Displays the list of songs within a specific songbook.
 *
 * @param viewModel Shared [SongViewModel] for retrieving songbook and song data.
 * @param songbookId The ID of the songbook to display (e.g., "CP", "MP").
 * @param onSongClick Callback invoked when a song item is tapped. Receives the song ID.
 * @param onBackClick Callback invoked when the back arrow is tapped.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SongListScreen(
    viewModel: SongViewModel,
    songbookId: String,
    onSongClick: (String) -> Unit,
    onBackClick: () -> Unit
) {
    // Retrieve the songbook metadata for the title bar
    val songbook = viewModel.getSongbookById(songbookId)

    // Retrieve all songs in this songbook, sorted by number
    val songs = viewModel.getSongsBySongbook(songbookId)

    Column(modifier = Modifier.fillMaxSize()) {
        // -----------------------------------------------------------------
        // TOP APP BAR — Songbook name and back navigation
        // -----------------------------------------------------------------
        TopAppBar(
            title = {
                Text(
                    text = songbook?.name ?: songbookId,
                    fontWeight = FontWeight.Bold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            },
            navigationIcon = {
                // Back arrow — returns to the home screen songbook grid
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
        // SONG LIST — Scrollable list of songs in this songbook
        // -----------------------------------------------------------------
        LazyColumn(
            modifier = Modifier.fillMaxSize()
        ) {
            items(
                items = songs,
                key = { it.id }
            ) { song ->
                SongListItem(
                    song = song,
                    onClick = { onSongClick(song.id) }
                )
                // Visual divider between song items
                HorizontalDivider(
                    modifier = Modifier.padding(horizontal = 16.dp),
                    color = MaterialTheme.colorScheme.outlineVariant
                )
            }
        }
    }
}

// =============================================================================
// SONG LIST ITEM
// =============================================================================

/**
 * A single row in the song list showing the song number and title.
 *
 * The song number is displayed in a fixed-width column on the left for
 * consistent alignment, followed by the song title which fills the
 * remaining width and truncates with ellipsis if too long.
 *
 * @param song The song data to display.
 * @param onClick Callback when the item is tapped.
 */
@Composable
private fun SongListItem(
    song: Song,
    onClick: () -> Unit
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        // Song number — fixed width column for alignment
        Text(
            text = "${song.number}.",
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = FontWeight.SemiBold,
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.width(56.dp)
        )

        // Song title — fills remaining width, truncates if too long
        Text(
            text = song.title,
            style = MaterialTheme.typography.bodyLarge,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f)
        )
    }
}
