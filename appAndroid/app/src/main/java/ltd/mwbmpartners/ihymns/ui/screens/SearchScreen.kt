// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Search Screen
//
// PURPOSE:
// Provides a search interface for finding songs across all songbooks.
// The user types a query into a TextField and results are filtered in
// real-time. Search matches against:
//   - Song title (case-insensitive substring match)
//   - Song number (prefix match)
//   - Lyric content (case-insensitive substring match across all lines)
//
// LAYOUT:
// - Top section with search TextField and clear button
// - Result count indicator
// - LazyColumn of matching songs with songbook badge and title
//
// PERFORMANCE:
// Filtering is performed in the ViewModel on every keystroke. For 3,600+
// songs, the in-memory string matching is fast enough (< 10ms) that no
// debouncing is needed. Results are rendered in a LazyColumn for efficient
// scrolling.
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Clear
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ltd.mwbmpartners.ihymns.models.Song
import ltd.mwbmpartners.ihymns.viewmodel.SongViewModel

// =============================================================================
// SEARCH SCREEN COMPOSABLE
// =============================================================================

/**
 * Search screen with a text input field and filtered song results.
 *
 * @param viewModel Shared [SongViewModel] for search state and results.
 * @param onSongClick Callback invoked when a search result is tapped.
 *                    Receives the song ID (e.g., "CP-0001").
 */
@Composable
fun SearchScreen(
    viewModel: SongViewModel,
    onSongClick: (String) -> Unit
) {
    // Collect reactive search state from the ViewModel
    val searchQuery by viewModel.searchQuery.collectAsState()
    val searchResults by viewModel.searchResults.collectAsState()

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(top = 8.dp)
    ) {
        // -----------------------------------------------------------------
        // SEARCH INPUT FIELD
        //
        // OutlinedTextField with a search icon (leading) and clear button
        // (trailing). Updates the ViewModel search query on every keystroke.
        // -----------------------------------------------------------------
        OutlinedTextField(
            value = searchQuery,
            onValueChange = { query ->
                viewModel.updateSearchQuery(query)
            },
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp),
            placeholder = { Text("Search songs, hymns, lyrics...") },
            leadingIcon = {
                Icon(
                    imageVector = Icons.Default.Search,
                    contentDescription = "Search"
                )
            },
            trailingIcon = {
                // Show clear button only when there is text in the field
                if (searchQuery.isNotEmpty()) {
                    IconButton(onClick = { viewModel.updateSearchQuery("") }) {
                        Icon(
                            imageVector = Icons.Default.Clear,
                            contentDescription = "Clear search"
                        )
                    }
                }
            },
            singleLine = true
        )

        Spacer(modifier = Modifier.height(8.dp))

        // -----------------------------------------------------------------
        // RESULT COUNT / STATUS
        // -----------------------------------------------------------------
        when {
            searchQuery.isBlank() -> {
                // No query entered — show helpful prompt
                Text(
                    text = "Search by title, song number, or lyrics",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)
                )
            }

            searchResults.isEmpty() -> {
                // Query entered but no results found
                Text(
                    text = "No songs found for \"$searchQuery\"",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)
                )
            }

            else -> {
                // Show result count
                Text(
                    text = "${searchResults.size} result${if (searchResults.size != 1) "s" else ""} found",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)
                )
            }
        }

        // -----------------------------------------------------------------
        // SEARCH RESULTS LIST
        // -----------------------------------------------------------------
        LazyColumn(
            modifier = Modifier.fillMaxSize()
        ) {
            items(
                items = searchResults,
                key = { it.id }
            ) { song ->
                SearchResultItem(
                    song = song,
                    onClick = { onSongClick(song.id) }
                )
                HorizontalDivider(
                    modifier = Modifier.padding(horizontal = 16.dp),
                    color = MaterialTheme.colorScheme.outlineVariant
                )
            }
        }
    }
}

// =============================================================================
// SEARCH RESULT ITEM
// =============================================================================

/**
 * A single search result row displaying the songbook badge, song number,
 * and title.
 *
 * @param song The song to display.
 * @param onClick Callback when the item is tapped.
 */
@Composable
private fun SearchResultItem(
    song: Song,
    onClick: () -> Unit
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        // Songbook ID badge — short identifier (e.g., "CP", "MP")
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
