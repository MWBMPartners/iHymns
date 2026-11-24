// APIClient+ViewHistory.swift
// IHAPI
//
// ELI5: The three "phone calls" for the person's viewing history, plus the
// decoding of their answers.
//
// DETAILED: PR 3b step I3 (#2180). The two reads are idempotent GETs, so they
// use the retrying `performIdempotentGET` like every other read. `songView`
// is a POST that is deliberately NOT retried (`performOnce`): a view is
// fire-and-forget and a retry could count it twice. Decoding is lossy per row
// (see `IHModels/ViewedSong.swift`): a row with an id `SongID` cannot parse
// is dropped, never a failure of the whole list.
import Foundation
import IHModels

extension APIClient {
    /// Records that the signed-in person opened `songId` (fire-and-forget).
    ///
    /// ELI5: "Tell the server I just opened this song."
    ///
    /// - Throws: `.rateLimited` (callers ignore it), `.offline`, `.maintenance`,
    ///   `.unauthorized`, ...: every failure is the caller's to swallow.
    public func songView(songId: SongID, deviceId: String? = nil) async throws {
        _ = try await performOnce(try Endpoint.songView(songId: songId, deviceId: deviceId))
    }

    /// The person's recently viewed songs, newest first.
    ///
    /// ELI5: "What have I looked at lately?"
    public func songHistory() async throws -> [ViewedSong] {
        let data = try await performIdempotentGET(.songHistory)
        return try Self.decodeSongHistory(from: data)
    }

    /// The person's most viewed songs.
    ///
    /// ELI5: "Which songs do I open most?"
    ///
    /// - Throws: `.server(status: 409, message: "profile_sync_disabled")` while
    ///   the site has the new account behaviour off: the caller shows nothing.
    public func myMostViewed(period: MostViewedPeriod? = nil) async throws -> MostViewedResult {
        let data = try await performIdempotentGET(.myMostViewed(period: period))
        return try Self.decodeMostViewed(from: data)
    }

    /// Decodes `{"history": [...]}`, skipping any row it cannot read.
    nonisolated public static func decodeSongHistory(from data: Data) throws -> [ViewedSong] {
        struct Envelope: Decodable { let history: [LossyRow<ViewedSong>] }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).history.compactMap(\.value)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes `{"songs": [...], "period": "12m"}`, skipping any row it cannot read.
    nonisolated public static func decodeMostViewed(from data: Data) throws -> MostViewedResult {
        struct Envelope: Decodable {
            let songs: [LossyRow<MostViewedSong>]
            let period: String?
        }
        do {
            let envelope = try JSONDecoder().decode(Envelope.self, from: data)
            return MostViewedResult(songs: envelope.songs.compactMap(\.value), period: envelope.period ?? MostViewedPeriod.twelveMonths.rawValue)
        } catch {
            throw APIError.decoding
        }
    }
}

/// One list row that decodes to `nil` instead of throwing, so one unreadable
/// row (an id `SongID` rejects) cannot sink the list around it.
private struct LossyRow<Value: Decodable>: Decodable {
    let value: Value?

    init(from decoder: Decoder) throws {
        value = try? Value(from: decoder)
    }
}
