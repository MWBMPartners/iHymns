// MediaFileNaming.swift
// IHModels
//
// ELI5: One small helper that takes a file name a curator typed on the web
// backend (e.g. "amazing-grace.mid") and makes sure it's actually SAFE to
// write to disk on this device — no sneaky ".." or "/" that could make the
// file land somewhere it shouldn't.
//
// DETAILED: #1440 (offline media caching) extracted this out of
// `IHFeatures.MediaDownloadViewModel` (#184's original "download to a temp
// file for ShareLink" helper, `sanitizedFileName(_:)`) so BOTH that
// ShareLink-scoped temp download AND the new permanent
// `IHPersistence.OfflineStore` media cache (`OfflineStore+MediaCache.swift`)
// call the exact SAME sanitizer instead of maintaining two copies that could
// silently drift — the repo's modularity rule ("if a piece of logic exists
// in more than one place, extract it into a shared module") applied across
// the `IHFeatures`/`IHPersistence` layering boundary. `IHModels` is the one
// module BOTH already depend on (`Package.swift`'s layering diagram: neither
// `IHFeatures` nor `IHPersistence` may depend on the other's siblings, but
// both sit above `IHModels`), so this is the correct shared home — moving it
// into either concrete module would have made the OTHER one depend on it
// transitively for no good reason.
//
// `SongMediaAsset.fileName` (`SongDetail.swift`) comes from the backend
// (curator-entered, not raw end-user input), but this client has no way to
// independently verify that server-side validation actually ran, so treating
// it as untrusted at the point it becomes a LOCAL FILE NAME is the same
// defensive posture `.claude/CLAUDE.md`'s input-handling rules ask of every
// layer that touches externally-sourced strings on the web side, mirrored
// here.
import Foundation

/// Turns a server-supplied file name into a safe local leaf name.
///
/// ELI5: "Make this file name safe to actually save to disk."
public enum MediaFileNaming {
    /// Strips path separators/traversal sequences out of `raw`, falling back
    /// to a neutral default if nothing safe is left.
    ///
    /// - Returns: A plain leaf file name — never containing `"/"`, and never
    ///   equal to (or derived entirely from) `".."` — so a caller can safely
    ///   append this directly to a directory `URL` without it being able to
    ///   escape that directory.
    public static func sanitizedFileName(_ raw: String) -> String {
        let lastComponent = (raw as NSString).lastPathComponent
        let cleaned = lastComponent.replacingOccurrences(of: "..", with: "")
        return cleaned.isEmpty ? "download" : cleaned
    }
}
