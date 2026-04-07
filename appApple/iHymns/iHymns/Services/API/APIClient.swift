// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  APIClient.swift
//  iHymns
//
//  A modern async/await networking client that communicates with the
//  iHymns REST API at ihymns.app/api. Handles JSON search, songbook
//  listing, random song selection, and shared set list operations.
//
//  The client uses URLSession with ETag caching for efficient data
//  transfer, and gracefully falls back to bundled data when offline.
//

import Foundation

// MARK: - APIClient

/// The primary networking service for communicating with the iHymns API.
/// All methods are async and throw on network or decoding failures.
///
/// Usage:
///   let client = APIClient()
///   let results = try await client.searchSongs(query: "amazing grace")
///
actor APIClient {

    // MARK: - Configuration

    /// The base URL for all API requests.
    private let baseURL: URL

    /// Shared URLSession with custom configuration for caching.
    private let session: URLSession

    /// Cache for ETag values to enable conditional requests.
    private var etagCache: [String: String] = [:]

    // MARK: - Initialiser

    /// Creates a new API client pointed at the given base URL.
    ///
    /// - Parameter baseURL: The root API URL. Defaults to the production API.
    init(baseURL: URL = URL(string: "https://ihymns.app/api")!) {
        self.baseURL = baseURL

        // Configure URLSession with moderate caching and timeouts
        let config = URLSessionConfiguration.default
        config.requestCachePolicy = .reloadRevalidatingCacheData
        config.timeoutIntervalForRequest = 15
        config.timeoutIntervalForResource = 30
        config.waitsForConnectivity = true
        config.httpAdditionalHeaders = [
            "Accept": "application/json",
            "X-Client": "iHymns-Apple/\(AppInfo.Application.Version.number)"
        ]
        self.session = URLSession(configuration: config)
    }

    // MARK: - Song Search

    /// Searches for songs matching the given query string.
    ///
    /// - Parameters:
    ///   - query: The search text to match against titles, lyrics, writers, etc.
    ///   - songbook: Optional songbook ID to restrict search to a single book.
    ///   - limit: Maximum number of results (default: 50).
    /// - Returns: A `SearchResponse` containing matching songs and total count.
    func searchSongs(
        query: String,
        songbook: String? = nil,
        limit: Int = 50
    ) async throws -> SearchResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        var queryItems = [
            URLQueryItem(name: "action", value: "search"),
            URLQueryItem(name: "q", value: query),
            URLQueryItem(name: "limit", value: String(limit))
        ]
        if let songbook = songbook {
            queryItems.append(URLQueryItem(name: "songbook", value: songbook))
        }
        components.queryItems = queryItems

        return try await fetch(SearchResponse.self, from: components.url!)
    }

    /// Searches for songs by number within a specific songbook.
    ///
    /// - Parameters:
    ///   - songbook: The songbook ID (e.g., "MP").
    ///   - number: The song number to search for.
    /// - Returns: A `SearchResponse` with matching songs.
    func searchByNumber(
        songbook: String,
        number: String
    ) async throws -> SearchResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "search_num"),
            URLQueryItem(name: "songbook", value: songbook),
            URLQueryItem(name: "number", value: number)
        ]

        return try await fetch(SearchResponse.self, from: components.url!)
    }

    // MARK: - Song Data

    /// Fetches the full data for a specific song by ID.
    ///
    /// - Parameter id: The song identifier (e.g., "CP-0001").
    /// - Returns: A `SongResponse` containing the song data.
    func fetchSong(id: String) async throws -> SongResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "song_data"),
            URLQueryItem(name: "id", value: id)
        ]

        return try await fetch(SongResponse.self, from: components.url!)
    }

    /// Fetches a random song, optionally from a specific songbook.
    ///
    /// - Parameter songbook: Optional songbook ID to restrict selection.
    /// - Returns: A `SongResponse` containing the random song.
    func fetchRandomSong(songbook: String? = nil) async throws -> SongResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        var queryItems = [URLQueryItem(name: "action", value: "random")]
        if let songbook = songbook {
            queryItems.append(URLQueryItem(name: "songbook", value: songbook))
        }
        components.queryItems = queryItems

        return try await fetch(SongResponse.self, from: components.url!)
    }

    // MARK: - Songbook Data

    /// Fetches the list of all available songbooks with metadata.
    ///
    /// - Returns: A `SongbooksResponse` containing all songbook entries.
    func fetchSongbooks() async throws -> SongbooksResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "songbooks")
        ]

        return try await fetch(SongbooksResponse.self, from: components.url!)
    }

    /// Fetches collection-wide statistics.
    ///
    /// - Returns: A `StatsResponse` with song and songbook counts.
    func fetchStats() async throws -> StatsResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "stats")
        ]

        return try await fetch(StatsResponse.self, from: components.url!)
    }

    // MARK: - Songs JSON (Full Catalogue)

    /// Fetches the complete songs.json catalogue, using ETag caching
    /// for efficient conditional requests.
    ///
    /// - Returns: The decoded `SongData` catalogue, or `nil` if unchanged (304).
    func fetchSongsJSON() async throws -> SongData? {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "songs_json")
        ]

        let url = components.url!
        var request = URLRequest(url: url)

        // Attach cached ETag for conditional request
        if let etag = etagCache[url.absoluteString] {
            request.addValue(etag, forHTTPHeaderField: "If-None-Match")
        }

        let (data, response) = try await session.data(for: request)

        guard let httpResponse = response as? HTTPURLResponse else {
            throw APIError.invalidResponse
        }

        // 304 Not Modified — our cached data is still current
        if httpResponse.statusCode == 304 {
            return nil
        }

        guard (200...299).contains(httpResponse.statusCode) else {
            throw APIError.httpError(statusCode: httpResponse.statusCode)
        }

        // Cache the new ETag for future conditional requests
        if let etag = httpResponse.value(forHTTPHeaderField: "ETag") {
            etagCache[url.absoluteString] = etag
        }

        return try JSONDecoder().decode(SongData.self, from: data)
    }

    // MARK: - Set Lists

    /// Creates or updates a shared set list on the server.
    ///
    /// - Parameter setList: The set list data to share.
    /// - Returns: A `SetListShareResponse` with the generated share ID and URL.
    func shareSetList(_ setList: SharedSetList) async throws -> SetListShareResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "setlist_share")
        ]

        var request = URLRequest(url: components.url!)
        request.httpMethod = "POST"
        request.addValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONEncoder().encode(setList)

        let (data, response) = try await session.data(for: request)

        guard let httpResponse = response as? HTTPURLResponse,
              (200...299).contains(httpResponse.statusCode) else {
            throw APIError.invalidResponse
        }

        return try JSONDecoder().decode(SetListShareResponse.self, from: data)
    }

    /// Retrieves a shared set list by its share ID.
    ///
    /// - Parameter id: The 8-character hex share ID.
    /// - Returns: A `SharedSetListResponse` with the set list data.
    func fetchSharedSetList(id: String) async throws -> SharedSetListResponse {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "setlist_get"),
            URLQueryItem(name: "id", value: id)
        ]

        return try await fetch(SharedSetListResponse.self, from: components.url!)
    }

    // MARK: - Private Helpers

    /// Generic JSON fetch and decode helper.
    private func fetch<T: Decodable>(_ type: T.Type, from url: URL) async throws -> T {
        let (data, response) = try await session.data(from: url)

        guard let httpResponse = response as? HTTPURLResponse else {
            throw APIError.invalidResponse
        }

        guard (200...299).contains(httpResponse.statusCode) else {
            throw APIError.httpError(statusCode: httpResponse.statusCode)
        }

        return try JSONDecoder().decode(T.self, from: data)
    }
}

// MARK: - API Error Types

/// Errors that can occur during API communication.
enum APIError: LocalizedError {

    /// The server returned a non-HTTP response.
    case invalidResponse

    /// The server returned an HTTP error status code.
    case httpError(statusCode: Int)

    /// The response body could not be decoded.
    case decodingError(underlying: Error)

    /// No network connectivity.
    case offline

    var errorDescription: String? {
        switch self {
        case .invalidResponse:
            return "Invalid response from server."
        case .httpError(let code):
            return "Server returned HTTP \(code)."
        case .decodingError(let error):
            return "Failed to decode response: \(error.localizedDescription)"
        case .offline:
            return "No internet connection. Using offline data."
        }
    }
}

// MARK: - API Response Types

/// Response from the search endpoint.
struct SearchResponse: Codable {
    let results: [Song]
    let total: Int
    let query: String?
}

/// Response from the song_data endpoint.
struct SongResponse: Codable {
    let song: Song
}

/// Response from the songbooks endpoint.
struct SongbooksResponse: Codable {
    let songbooks: [Songbook]
}

/// Response from the stats endpoint.
struct StatsResponse: Codable {
    let totalSongs: Int
    let totalSongbooks: Int
    let songbooks: [SongbookStats]
}

/// Songbook stats returned by the stats endpoint.
struct SongbookStats: Codable, Identifiable {
    let id: String
    let name: String
    let songCount: Int
}

/// Data sent when sharing a set list.
struct SharedSetList: Codable {
    let name: String
    let songs: [String]
    let owner: String
    var id: String?
}

/// Response from creating/updating a shared set list.
struct SetListShareResponse: Codable {
    let id: String
    let url: String
}

/// Response from fetching a shared set list.
struct SharedSetListResponse: Codable {
    let id: String
    let name: String
    let songs: [String]
    let created: String
    let updated: String
}
