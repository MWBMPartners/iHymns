// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Favourites Screen
//
// PURPOSE:
// Displays the user's favourite (bookmarked) songs in a scrollable list.
// Supports swipe-to-dismiss for removing songs from favourites with a
// visual background indicator during the swipe gesture.
//
// LAYOUT:
// - Top section with "Favourites" title and count
// - Empty state message when no favourites are saved
// - SwipeToDismiss items in a LazyColumn
// - Each item shows songbook badge, song number, and title
//
// PERSISTENCE:
// Favourites are stored in SharedPreferences as song IDs (managed by
// SongViewModel). Changes are persisted immediately and survive app restarts.
//
// SWIPE-TO-DISMISS:
// Users can swipe a favourite item horizontally to remove it. The background
// turns red with a delete icon during the swipe gesture to provide clear
// visual feedback. The removal is immediate (no undo snackbar in Phase 1).
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.FavoriteBorder
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ltd.mwbmpartners.ihymns.models.Song
import ltd.mwbmpartners.ihymns.viewmodel.SongViewModel

// =============================================================================
// FAVOURITES SCREEN COMPOSABLE
// =============================================================================

/**
 * Displays the user's favourite songs with swipe-to-dismiss removal.
 *
 * @param viewModel Shared [SongViewModel] for favourites data and management.
 * @param onSongClick Callback invoked when a favourite song is tapped.
 *                    Receives the song ID (e.g., "CP-0001").
 */
@Composable
fun FavoritesScreen(
    viewModel: SongViewModel,
    onSongClick: (String) -> Unit
) {
    // Collect the reactive list of favourite songs
    val favouriteSongs by viewModel.favouriteSongs.collectAsState()

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(top = 16.dp)
    ) {
        // -----------------------------------------------------------------
        // HEADER — Title and count
        // -----------------------------------------------------------------
        Text(
            text = "Favourites",
            style = MaterialTheme.typography.headlineMedium,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.padding(horizontal = 16.dp)
        )

        if (favouriteSongs.isNotEmpty()) {
            Text(
                text = "${favouriteSongs.size} song${if (favouriteSongs.size != 1) "s" else ""}",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp)
            )
        }

        // -----------------------------------------------------------------
        // CONTENT — Empty state or favourites list
        // -----------------------------------------------------------------
        if (favouriteSongs.isEmpty()) {
            // Empty state — no favourites saved yet
            EmptyFavouritesMessage()
        } else {
            // Favourites list with swipe-to-dismiss
            FavouritesList(
                songs = favouriteSongs,
                onSongClick = onSongClick,
                onRemove = { songId ->
                    viewModel.removeFavourite(songId)
                }
            )
        }
    }
}

// =============================================================================
// EMPTY STATE
// =============================================================================

/**
 * Displays a friendly empty state message when the user has no favourites.
 *
 * Includes an outlined heart icon and instructional text explaining how
 * to add favourites.
 */
@Composable
private fun EmptyFavouritesMessage() {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        // Large heart outline icon
        Icon(
            imageVector = Icons.Default.FavoriteBorder,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.5f),
            modifier = Modifier.padding(bottom = 16.dp)
        )

        // Instructional message
        Text(
            text = "No favourites yet",
            style = MaterialTheme.typography.titleLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center
        )

        Text(
            text = "Tap the heart icon on any song to add it to your favourites.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.7f),
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = 8.dp)
        )
    }
}

// =============================================================================
// FAVOURITES LIST WITH SWIPE-TO-DISMISS
// =============================================================================

/**
 * Renders the favourites list with swipe-to-dismiss functionality.
 *
 * @param songs List of favourite [Song] objects to display.
 * @param onSongClick Callback when a song item is tapped.
 * @param onRemove Callback when a song is swiped away (removed from favourites).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FavouritesList(
    songs: List<Song>,
    onSongClick: (String) -> Unit,
    onRemove: (String) -> Unit
) {
    LazyColumn(
        modifier = Modifier.fillMaxSize()
    ) {
        items(
            items = songs,
            key = { it.id }
        ) { song ->
            // Swipe-to-dismiss state for this item
            val dismissState = rememberSwipeToDismissBoxState()

            // When the item is dismissed (swiped past threshold), remove it
            if (dismissState.currentValue == SwipeToDismissBoxValue.EndToStart) {
                LaunchedEffect(song.id) {
                    onRemove(song.id)
                }
            }

            SwipeToDismissBox(
                state = dismissState,
                enableDismissFromStartToEnd = false,
                enableDismissFromEndToStart = true,
                backgroundContent = {
                    // Red background with delete icon shown during swipe
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .background(Color.Red.copy(alpha = 0.9f))
                            .padding(horizontal = 20.dp),
                        contentAlignment = Alignment.CenterEnd
                    ) {
                        Icon(
                            imageVector = Icons.Default.Delete,
                            contentDescription = "Remove from favourites",
                            tint = Color.White
                        )
                    }
                }
            ) {
                // Foreground content — the song item
                FavouriteItem(
                    song = song,
                    onClick = { onSongClick(song.id) }
                )
            }

            HorizontalDivider(
                modifier = Modifier.padding(horizontal = 16.dp),
                color = MaterialTheme.colorScheme.outlineVariant
            )
        }
    }
}

// =============================================================================
// FAVOURITE ITEM
// =============================================================================

/**
 * A single row in the favourites list showing songbook, number, and title.
 *
 * @param song The favourite song to display.
 * @param onClick Callback when the item is tapped.
 */
@Composable
private fun FavouriteItem(
    song: Song,
    onClick: () -> Unit
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.surface)
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        // Songbook ID badge
        Text(
            text = song.songbook,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.width(48.dp)
        )

        // Song number
        Text(
            text = "${song.number}.",
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.width(48.dp)
        )

        // Song title
        Text(
            text = song.title,
            style = MaterialTheme.typography.bodyLarge,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f)
        )
    }
}
