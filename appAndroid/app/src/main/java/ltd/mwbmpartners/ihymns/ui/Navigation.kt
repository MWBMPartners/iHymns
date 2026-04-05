// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Navigation Graph (Jetpack Compose Navigation)
//
// PURPOSE:
// Defines the top-level composable [iHymnsApp] which hosts the navigation
// graph for the entire application. Uses Jetpack Compose Navigation with
// route-based navigation to manage screen transitions.
//
// ROUTES:
//   "home"            — Home screen with songbook grid
//   "songbook/{id}"   — Song list for a specific songbook
//   "song/{id}"       — Song detail view with full lyrics
//   "favourites"      — User's favourite songs list
//   "search"          — Search screen with filtered results
//   "help"            — Help and about information
//
// ARCHITECTURE:
// A single NavHost manages all screen destinations. The SongViewModel is
// shared across all screens via the viewModel() Compose function, ensuring
// consistent state (loaded songs, favourites, search query) throughout
// the navigation stack.
//
// PLATFORM NOTES:
// - On Android TV / Fire TV, the back button on the remote maps to the
//   system back press, which NavController handles automatically (pop).
// - On ChromeOS, keyboard Escape key also triggers back navigation.
// =============================================================================

package ltd.mwbmpartners.ihymns.ui

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import ltd.mwbmpartners.ihymns.ui.screens.FavoritesScreen
import ltd.mwbmpartners.ihymns.ui.screens.HomeScreen
import ltd.mwbmpartners.ihymns.ui.screens.HelpScreen
import ltd.mwbmpartners.ihymns.ui.screens.SearchScreen
import ltd.mwbmpartners.ihymns.ui.screens.SongDetailScreen
import ltd.mwbmpartners.ihymns.ui.screens.SongListScreen
import ltd.mwbmpartners.ihymns.viewmodel.SongViewModel

// =============================================================================
// ROUTE CONSTANTS
//
// Centralised route strings to avoid scattered string literals. Using
// constants prevents typos and makes route references refactor-safe.
// =============================================================================

/** Route: Home screen displaying the songbook grid */
const val ROUTE_HOME = "home"

/** Route: Song list for a specific songbook. Argument: songbook ID string. */
const val ROUTE_SONGBOOK = "songbook/{id}"

/** Route: Song detail view with full lyrics. Argument: song ID string. */
const val ROUTE_SONG = "song/{id}"

/** Route: User's favourite songs list */
const val ROUTE_FAVOURITES = "favourites"

/** Route: Search screen with query input and filtered results */
const val ROUTE_SEARCH = "search"

/** Route: Help and about information screen */
const val ROUTE_HELP = "help"

// =============================================================================
// BOTTOM NAVIGATION ITEMS
// =============================================================================

/**
 * Data class representing a single item in the bottom navigation bar.
 *
 * @property route The navigation route this item navigates to.
 * @property label Display label shown below the icon.
 * @property icon Material icon displayed in the navigation bar.
 */
private data class BottomNavItem(
    val route: String,
    val label: String,
    val icon: androidx.compose.ui.graphics.vector.ImageVector
)

/** Bottom navigation bar items — Home, Search, Favourites */
private val bottomNavItems = listOf(
    BottomNavItem(ROUTE_HOME, "Home", Icons.Default.Home),
    BottomNavItem(ROUTE_SEARCH, "Search", Icons.Default.Search),
    BottomNavItem(ROUTE_FAVOURITES, "Favourites", Icons.Default.Favorite)
)

// =============================================================================
// TOP-LEVEL APP COMPOSABLE
// =============================================================================

/**
 * Top-level composable that sets up the navigation graph for iHymns.
 *
 * This composable:
 * 1. Creates and remembers the [NavController] for managing navigation state
 * 2. Creates a shared [SongViewModel] instance scoped to the activity
 * 3. Renders a [Scaffold] with bottom navigation bar
 * 4. Hosts the [NavHost] with all screen route destinations
 *
 * Called from [MainActivity.onCreate] within the theme wrapper.
 */
@Composable
fun iHymnsApp() {
    // Create the navigation controller that manages the back stack
    val navController = rememberNavController()

    // Shared ViewModel — scoped to the activity so all screens share the same
    // song data, favourites state, and search query
    val viewModel: SongViewModel = viewModel()

    // Observe the current back stack entry to highlight the active nav item
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = navBackStackEntry?.destination

    Scaffold(
        // -----------------------------------------------------------------
        // BOTTOM NAVIGATION BAR
        //
        // Displays Home, Search, and Favourites tabs. Only shown on
        // top-level destinations (not on song detail or songbook list).
        // -----------------------------------------------------------------
        bottomBar = {
            // Determine if we should show the bottom bar (only on top-level routes)
            val topLevelRoutes = setOf(ROUTE_HOME, ROUTE_SEARCH, ROUTE_FAVOURITES)
            val currentRoute = currentDestination?.route
            val showBottomBar = currentRoute in topLevelRoutes

            if (showBottomBar) {
                NavigationBar {
                    bottomNavItems.forEach { item ->
                        NavigationBarItem(
                            // Highlight the item if the current destination
                            // matches this item's route in the hierarchy
                            selected = currentDestination?.hierarchy?.any {
                                it.route == item.route
                            } == true,
                            onClick = {
                                navController.navigate(item.route) {
                                    // Pop up to the start destination to avoid
                                    // building up a large back stack of top-level
                                    // destinations
                                    popUpTo(navController.graph.findStartDestination().id) {
                                        saveState = true
                                    }
                                    // Avoid duplicate copies of the same destination
                                    launchSingleTop = true
                                    // Restore state when re-selecting a tab
                                    restoreState = true
                                }
                            },
                            icon = {
                                Icon(
                                    imageVector = item.icon,
                                    contentDescription = item.label
                                )
                            },
                            label = { Text(item.label) }
                        )
                    }
                }
            }
        }
    ) { innerPadding ->
        // =================================================================
        // NAVIGATION HOST — Screen Route Definitions
        //
        // The NavHost renders the current screen based on the active route.
        // Each composable { } block maps a route to a screen composable.
        // =================================================================
        NavHost(
            navController = navController,
            startDestination = ROUTE_HOME,
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
        ) {
            // =============================================================
            // HOME — Songbook grid
            // =============================================================
            composable(ROUTE_HOME) {
                HomeScreen(
                    viewModel = viewModel,
                    onSongbookClick = { songbookId ->
                        navController.navigate("songbook/$songbookId")
                    },
                    onHelpClick = {
                        navController.navigate(ROUTE_HELP)
                    }
                )
            }

            // =============================================================
            // SONGBOOK — Song list for a specific songbook
            //
            // Argument: {id} = songbook ID (e.g., "CP", "MP", "SDAH")
            // =============================================================
            composable(
                route = ROUTE_SONGBOOK,
                arguments = listOf(
                    navArgument("id") { type = NavType.StringType }
                )
            ) { backStackEntry ->
                val songbookId = backStackEntry.arguments?.getString("id") ?: ""
                SongListScreen(
                    viewModel = viewModel,
                    songbookId = songbookId,
                    onSongClick = { songId ->
                        navController.navigate("song/$songId")
                    },
                    onBackClick = {
                        navController.popBackStack()
                    }
                )
            }

            // =============================================================
            // SONG DETAIL — Full lyrics display
            //
            // Argument: {id} = song ID (e.g., "CP-0001", "MP-0523")
            // =============================================================
            composable(
                route = ROUTE_SONG,
                arguments = listOf(
                    navArgument("id") { type = NavType.StringType }
                )
            ) { backStackEntry ->
                val songId = backStackEntry.arguments?.getString("id") ?: ""
                SongDetailScreen(
                    viewModel = viewModel,
                    songId = songId,
                    onBackClick = {
                        navController.popBackStack()
                    }
                )
            }

            // =============================================================
            // FAVOURITES — User's saved favourite songs
            // =============================================================
            composable(ROUTE_FAVOURITES) {
                FavoritesScreen(
                    viewModel = viewModel,
                    onSongClick = { songId ->
                        navController.navigate("song/$songId")
                    }
                )
            }

            // =============================================================
            // SEARCH — Song search with filtered results
            // =============================================================
            composable(ROUTE_SEARCH) {
                SearchScreen(
                    viewModel = viewModel,
                    onSongClick = { songId ->
                        navController.navigate("song/$songId")
                    }
                )
            }

            // =============================================================
            // HELP — Application information and usage help
            // =============================================================
            composable(ROUTE_HELP) {
                HelpScreen(
                    onBackClick = {
                        navController.popBackStack()
                    }
                )
            }
        }
    }
}
