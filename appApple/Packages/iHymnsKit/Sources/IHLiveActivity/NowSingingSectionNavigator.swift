// NowSingingSectionNavigator.swift
// IHLiveActivity
//
// ELI5: Works out "which section should we jump to" when someone taps
// Next/Previous on the Live Activity card — clamped so you can never go
// before the first section, and (when we know how many sections the song
// has) never past the last one.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). A PURE function, no
// ActivityKit/AppIntents import — `AppRootViewModel+LiveActivity.swift`'s
// `hostAdvanceSection(_:)` is the ONE caller (`IHFeatures`, which imports
// this target). Returning `nil` is the deliberate "no-op, nothing to
// broadcast" signal, mirroring `LiveFollowEngine.broadcast(songId
// :componentIndex:)`'s own dedup contract one layer up — a Next tap at the
// last known section (or a Previous tap at the first) never fires a
// redundant `live_follow_update` POST.
import Foundation

/// Computes the target section index for a Live Activity Next/Prev tap. See
/// this file's header for the no-op (`nil`) contract.
public enum NowSingingSectionNavigator {
    /// - Parameters:
    ///   - current: The host's current section index, or `nil` if no song
    ///     (or no section) has been broadcast yet.
    ///   - delta: `+1` for Next, `-1` for Previous. `0` is always a no-op.
    ///   - sectionCount: The song's total section count, when known — `nil`
    ///     when the host hasn't loaded that (still clamps the LOWER bound at
    ///     `0` either way; only the UPPER clamp is skipped).
    /// - Returns: The new index, or `nil` if the move would be a no-op
    ///   (already at a clamped boundary, or `delta == 0`).
    public static func target(current: Int?, delta: Int, sectionCount: Int?) -> Int? {
        guard delta != 0 else { return nil }

        guard let current else {
            // Nothing broadcast yet: Next starts at the first section (0);
            // Previous has nowhere to go, so it's a no-op.
            return delta > 0 ? 0 : nil
        }

        var next = max(0, current + delta)
        if let sectionCount, sectionCount > 0 {
            next = min(next, sectionCount - 1)
        }
        return next == current ? nil : next
    }
}
