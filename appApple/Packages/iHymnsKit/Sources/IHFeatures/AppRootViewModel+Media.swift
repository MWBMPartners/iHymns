// AppRootViewModel+Media.swift
// IHFeatures
//
// ELI5: The one place a song screen asks "what's the real web address for
// this audio/PDF/MIDI/MusicXML file?" — mirrors how every other network
// operation reaches through `AppRootViewModel` rather than a view holding
// its own `APIClient` reference.
//
// DETAILED: #184 (Apple Phase 1: audio & sheet music). Deliberately a THIN,
// synchronous pass-through — no network call happens here, just resolving a
// server-relative `SongMediaAsset.streamUrl` against `apiClient.environment`
// (`APIClient.swift`'s `#184` update, `APIEnvironment+Media.swift`'s
// `mediaURL(forStreamPath:)`) — kept in its own extension file purely
// because a Swift extension can't add new stored properties, the same
// reasoning every other `AppRootViewModel+*.swift` file's header already
// documents (`+Offline.swift`, `+Favorites.swift`, ...). `SongAudioPlayerViewModel`/
// `SheetMusicViewModel`/`MediaDownloadViewModel` (this task's new player/PDF/
// download engines) call this rather than reaching into `apiClient` directly,
// keeping `apiClient` itself `internal` exactly as its own doc comment on
// `AppRootViewModel.swift` requires.
import Foundation
import IHAPI

extension AppRootViewModel {
    /// Resolves a media asset's server-relative `streamUrl` (e.g.
    /// `"/song-media/123"`) into an absolute URL for whichever backend this
    /// app instance is currently talking to.
    ///
    /// ELI5: "Turn this short server path into the real web address."
    public func mediaURL(forStreamPath path: String) -> URL? {
        apiClient.environment.mediaURL(forStreamPath: path)
    }
}
