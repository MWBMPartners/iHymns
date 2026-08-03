// CopyrightDisplay.swift
// IHModels
//
// ELI5: One tiny helper that decides what copyright line to actually print
// for a song OR a work — "years + holder" when a curator has filled those
// in separately, otherwise the old one-line copyright text. Both
// `SongDetail` and `Work` ask this SAME helper instead of each working it
// out themselves, so a song page and a work page never disagree.
//
// DETAILED: #1752 Slice C extracts this fold out of `SongDetail.
// copyrightDisplay` (added in Slice A, #1752 §1.1) so `Work.copyrightDisplay`
// (`Work.swift`, #1752 §3.1) can share it — repo modularity rule ("when a
// piece of logic exists in more than one place, extract it into a shared
// module"), applied here BEFORE a second copy ever existed, per the build
// spec's explicit instruction not to duplicate the §1.1 body. Mirrors
// `includes/pages/song.php`'s `$copyrightDisplay` fold byte-for-byte
// (`.claude/catalogue-1741-1750-plan.md` §4.1 — the split-fields-first
// precedence rule, "never both"):
//   $copyrightSplit   = trim($copyrightYears . ' ' . $copyrightHolder);
//   $copyrightDisplay = $copyrightSplit !== '' ? $copyrightSplit : trim((string)$copyright);
// A free function (not a protocol/extension) because `SongDetail` and
// `Work` share no common supertype/protocol worth introducing just to hang
// one three-line computed property off of — rule #22's "one scorer/fold"
// requirement is about there being exactly ONE implementation, not about
// the Swift mechanism used to share it.
import Foundation

/// The copyright line to display for a song or work: the #1741 split
/// (years + holder) when either half is present, else the legacy free-text
/// copyright string. Never both.
///
/// - Parameters:
///   - years: The split `copyrightYears` field (`SongDetail.copyrightYears`/
///     `Work.copyrightYears`), e.g. `"1978, 1987, 2011"`. `nil`/empty when
///     unset or the server hasn't sent this key yet (pre-#1750/#1741 P4b).
///   - holder: The split `copyrightHolder` field. `nil`/empty likewise.
///   - legacy: The pre-existing one-line free-text copyright string
///     (`SongDetail.copyright`). `Work` has no equivalent free-text field
///     (`Work.notes` is NOT a legacy-copyright source — callers pass `""`).
/// - Returns: `"<years> <holder>"` (trimmed, single-spaced) when either half
///   is non-empty; otherwise the trimmed `legacy` string. Both may be empty,
///   in which case the result is `""` — callers gate rendering on that.
public func ihCopyrightDisplay(years: String?, holder: String?, legacy: String) -> String {
    let split = [years ?? "", holder ?? ""]
        .map { $0.trimmingCharacters(in: .whitespaces) }
        .filter { !$0.isEmpty }
        .joined(separator: " ")
    return split.isEmpty ? legacy.trimmingCharacters(in: .whitespaces) : split
}
