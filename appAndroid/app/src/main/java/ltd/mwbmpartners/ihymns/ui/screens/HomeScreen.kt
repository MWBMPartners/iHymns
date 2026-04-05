// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Home Screen (Songbook Grid)
//
// PURPOSE:
// Displays the main landing screen of the iHymns app. Shows a grid of
// available songbooks as tappable cards with an amber gradient background,
// matching the iLyrics dB visual identity. Each card displays the songbook
// name and song count. Tapping a card navigates to the song list for that
// songbook.
//
// LAYOUT:
// - Top bar with app title and help icon
// - Loading indicator while songs.json is being parsed
// - Error message if data loading fails
// - Responsive LazyVerticalGrid:
//     - 2 columns on phones (portrait)
//     - Adapts to wider layouts on tablets and ChromeOS
//
// DESIGN:
// Cards use an amber-to-deep-orange gradient background to match the warm
// colour scheme of the iLyrics dB / iHymns brand identity. White text
// overlaid on the gradient ensures readability.
// =============================================================================

package ltd.mwbmpartners.ihymns.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.HelpOutline
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ltd.mwbmpartners.ihymns.models.Songbook
import ltd.mwbmpartners.ihymns.viewmodel.SongViewModel

// =============================================================================
// COLOUR CONSTANTS — Amber Gradient for Songbook Cards
//
// These colours form the warm amber-to-deep-orange gradient used on songbook
// cards, matching the iLyrics dB / iHymns brand palette.
// =============================================================================

/** Amber gradient start colour — warm golden amber */
private val AmberGradientStart = Color(0xFFFFA000)

/** Amber gradient end colour — deep orange-amber */
private val AmberGradientEnd = Color(0xFFFF6F00)

// =============================================================================
// HOME SCREEN COMPOSABLE
// =============================================================================

/**
 * Home screen displaying a grid of available songbooks.
 *
 * @param viewModel Shared [SongViewModel] providing songbook data and loading state.
 * @param onSongbookClick Callback invoked when a songbook card is tapped.
 *                        Receives the songbook ID (e.g., "CP", "MP").
 * @param onHelpClick Callback invoked when the help icon is tapped.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    viewModel: SongViewModel,
    onSongbookClick: (String) -> Unit,
    onHelpClick: () -> Unit
) {
    // Collect reactive state from the ViewModel
    val songbooks by viewModel.songbooks.collectAsState()
    val isLoading by viewModel.isLoading.collectAsState()
    val errorMessage by viewModel.errorMessage.collectAsState()

    Column(modifier = Modifier.fillMaxSize()) {
        // -----------------------------------------------------------------
        // TOP APP BAR — App title and help action
        // -----------------------------------------------------------------
        TopAppBar(
            title = {
                Text(
                    text = "iHymns",
                    fontWeight = FontWeight.Bold
                )
            },
            actions = {
                // Help button — navigates to the help/about screen
                IconButton(onClick = onHelpClick) {
                    Icon(
                        imageVector = Icons.Default.HelpOutline,
                        contentDescription = "Help"
                    )
                }
            },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.primaryContainer,
                titleContentColor = MaterialTheme.colorScheme.onPrimaryContainer
            )
        )

        // -----------------------------------------------------------------
        // CONTENT AREA — Loading, Error, or Songbook Grid
        // -----------------------------------------------------------------
        when {
            // Show loading spinner while songs.json is being parsed
            isLoading -> {
                Box(
                    modifier = Modifier.fillMaxSize(),
                    contentAlignment = Alignment.Center
                ) {
                    CircularProgressIndicator(
                        color = AmberGradientStart
                    )
                }
            }

            // Show error message if data loading failed
            errorMessage != null -> {
                Box(
                    modifier = Modifier.fillMaxSize(),
                    contentAlignment = Alignment.Center
                ) {
                    Text(
                        text = errorMessage ?: "Unknown error",
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyLarge,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.padding(16.dp)
                    )
                }
            }

            // Show the songbook grid once data is loaded
            else -> {
                SongbookGrid(
                    songbooks = songbooks,
                    onSongbookClick = onSongbookClick
                )
            }
        }
    }
}

// =============================================================================
// SONGBOOK GRID
// =============================================================================

/**
 * Renders a responsive grid of songbook cards.
 *
 * Uses [LazyVerticalGrid] with adaptive column sizing:
 * - Minimum column width of 160dp ensures 2 columns on phones
 * - Wider screens (tablets, ChromeOS) automatically get more columns
 *
 * @param songbooks List of songbooks to display as cards.
 * @param onSongbookClick Callback when a songbook card is tapped.
 */
@Composable
private fun SongbookGrid(
    songbooks: List<Songbook>,
    onSongbookClick: (String) -> Unit
) {
    LazyVerticalGrid(
        columns = GridCells.Adaptive(minSize = 160.dp),
        contentPadding = PaddingValues(16.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
        modifier = Modifier.fillMaxSize()
    ) {
        items(
            items = songbooks,
            key = { it.id }
        ) { songbook ->
            SongbookCard(
                songbook = songbook,
                onClick = { onSongbookClick(songbook.id) }
            )
        }
    }
}

// =============================================================================
// SONGBOOK CARD
// =============================================================================

/**
 * A single songbook card with amber gradient background.
 *
 * Displays the songbook name and song count overlaid on a warm amber-to-deep-
 * orange gradient. The card has rounded corners and a subtle elevation shadow.
 *
 * @param songbook The songbook data to display.
 * @param onClick Callback when the card is tapped/clicked.
 */
@Composable
private fun SongbookCard(
    songbook: Songbook,
    onClick: () -> Unit
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .height(140.dp)
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(16.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 4.dp)
    ) {
        // Gradient background box containing the text overlay
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(
                    brush = Brush.verticalGradient(
                        colors = listOf(AmberGradientStart, AmberGradientEnd)
                    )
                ),
            contentAlignment = Alignment.Center
        ) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
                modifier = Modifier.padding(12.dp)
            ) {
                // Songbook name — primary label
                Text(
                    text = songbook.name,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = Color.White,
                    textAlign = TextAlign.Center
                )

                // Song count — secondary label
                Text(
                    text = "${songbook.songCount} songs",
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color.White.copy(alpha = 0.85f),
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 4.dp)
                )
            }
        }
    }
}
