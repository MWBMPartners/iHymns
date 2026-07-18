// RemoteControlSurfaceView.swift
// IHFeatures
//
// ELI5: The actual remote control — shows what song's up on the TV right
// now, and lets you jump to another song, move between verses/lines, black
// the screen out (or bring it back), and nudge the TV's look. Nothing you
// tap here changes anything ON THIS SCREEN directly — it just tells the TV
// what you want, and the TV's own next update is what actually redraws
// this screen.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §6.3 — BINDING,
// Decision D-7: STATELESS/REFLECTIVE). Every control here renders from the
// `IHRPState` the coordinator's `.controlling` phase carries and every
// interaction ONLY calls `sendIntent(...)` — nothing is optimistically
// toggled locally. The TV is the single display authority (strategy
// §2.4.1) and its `.state` broadcast (which echoes our OWN intents back,
// §1.1) is the ONLY thing that ever updates this view. This is what makes
// multi-remote last-writer-wins correct BY CONSTRUCTION: when another
// remote changes the display, this surface just re-renders the next
// broadcast — there is no local shadow state to fight over.
//
// **Content INVARIANT (binding):** every intent sent from here is a
// ~200-byte navigation intent (a song id, an index, an enum case) — no
// lyric text ever crosses this seam in either direction this view can
// observe (`IHRPMessage.swift`'s own header invariant).
import IHAuth
import IHLive
import IHDesign
import IHModels
import SwiftUI

/// The actual controlling surface — see this file's header for the
/// stateless/reflective design.
public struct RemoteControlSurfaceView: View {
    let tvName: String
    let state: IHRPState
    let rootViewModel: AppRootViewModel
    let onIntent: (IHRPMessage) -> Void
    let onDisconnect: () -> Void
    /// The PR-14 Service-Mode mirror (#1425, strategy §2.4.1) — `nil` on
    /// every call site that hasn't wired one up yet (defaults to `nil` so
    /// existing/preview construction stays source-compatible).
    let serviceMirror: ServiceMirrorController?

    @State private var isPresentingSongPicker = false

    public init(
        tvName: String, state: IHRPState, rootViewModel: AppRootViewModel,
        onIntent: @escaping (IHRPMessage) -> Void, onDisconnect: @escaping () -> Void,
        serviceMirror: ServiceMirrorController? = nil
    ) {
        self.tvName = tvName
        self.state = state
        self.rootViewModel = rootViewModel
        self.onIntent = onIntent
        self.onDisconnect = onDisconnect
        self.serviceMirror = serviceMirror
    }

    public var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                headerCard
                songCard
                lineCard
                displayCard
                appearanceCard
                mirrorCard
            }
            .padding()
        }
        .navigationTitle(tvName)
        .sheet(isPresented: $isPresentingSongPicker) {
            RemoteSongPickerView(
                rootViewModel: rootViewModel,
                onSelect: { songId in onIntent(.selectSong(songId: songId, componentIndex: nil, lineIndex: nil)) },
                onPrepare: { songId in onIntent(.prepare(songId: songId)) }
            )
        }
    }

    // MARK: - 1. Header

    private var headerCard: some View {
        HStack {
            Circle()
                .fill(.green)
                .frame(width: 10, height: 10)
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(tvName).font(.headline)
                Text(state.songId?.rawValue ?? "Nothing selected")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            Spacer(minLength: 12)
            Button("Disconnect", role: .destructive, action: onDisconnect)
        }
        .ihGlassCard()
        .accessibilityElement(children: .combine)
    }

    // MARK: - 2. Song

    private var songCard: some View {
        VStack(spacing: 12) {
            Button {
                isPresentingSongPicker = true
            } label: {
                Label("Choose Song…", systemImage: "music.note.list")
                    .frame(maxWidth: .infinity, minHeight: 44)
            }
            .buttonStyle(.borderedProminent)

            HStack {
                remoteButton("Previous verse", systemImage: "chevron.left") { onIntent(.prevComponent) }
                Spacer()
                // The TV resolves arrangement order itself — this remote
                // never counts components (`IHRPMessage.swift:89-92`).
                Text("Verse")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                Spacer()
                remoteButton("Next verse", systemImage: "chevron.right") { onIntent(.nextComponent) }
            }
        }
        .ihGlassCard()
    }

    // MARK: - 3. Line

    private var lineCard: some View {
        HStack {
            remoteButton("Previous line", systemImage: "arrow.up") { onIntent(.prevLine) }
            Spacer()
            remoteButton("Scroll up", systemImage: "arrow.up.to.line") { onIntent(.scroll(delta: -1)) }
            Spacer()
            remoteButton("Scroll down", systemImage: "arrow.down.to.line") { onIntent(.scroll(delta: 1)) }
            Spacer()
            remoteButton("Next line", systemImage: "arrow.down") { onIntent(.nextLine) }
        }
        .ihGlassCard()
    }

    // MARK: - 4. Display

    private var displayCard: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Display").font(.subheadline).foregroundStyle(.secondary)
            Picker("Display", selection: displayStateBinding) {
                Text("Lyrics").tag(IHRPDisplayState.lyrics)
                Text("Black").tag(IHRPDisplayState.blackout)
                Text("Logo").tag(IHRPDisplayState.logo)
                Text("Freeze").tag(IHRPDisplayState.frozen)
            }
            // `.segmented` is UNAVAILABLE on watchOS (#1549) — this screen
            // is never shown on the watch (`iHymnsWatch` links the whole
            // IHFeatures target, so it must still COMPILE there);
            // `.automatic` is a compile-only fallback, non-watch keeps
            // `.segmented` unchanged.
            #if os(watchOS)
            .pickerStyle(.automatic)
            #else
            .pickerStyle(.segmented)
            #endif
        }
        .ihGlassCard()
    }

    private var displayStateBinding: Binding<IHRPDisplayState> {
        Binding(get: { state.displayState }, set: { onIntent(.setDisplayState($0)) })
    }

    // MARK: - 5. Appearance

    private var appearanceCard: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Appearance").font(.subheadline).foregroundStyle(.secondary)
            // `Menu` is UNAVAILABLE on watchOS (#1549) — this screen is
            // never shown on the watch (`iHymnsWatch` links the whole
            // IHFeatures target, so it must still COMPILE there); `EmptyView`
            // is a compile-only stub, non-watch keeps the real theme menu
            // unchanged.
            #if os(watchOS)
            EmptyView()
            #else
            Menu {
                ForEach(Self.themeOptions, id: \.self) { theme in
                    Button(theme.capitalized) { onIntent(.setAppearance(theme: theme, textScale: nil)) }
                }
            } label: {
                Label("Theme", systemImage: "paintbrush")
            }
            #endif
            HStack {
                Text("Text Size")
                Spacer()
                Button {
                    onIntent(.setAppearance(theme: nil, textScale: 0.9))
                } label: {
                    Image(systemName: "textformat.size.smaller")
                }
                .accessibilityLabel("Smaller text")
                Button {
                    onIntent(.setAppearance(theme: nil, textScale: 1.1))
                } label: {
                    Image(systemName: "textformat.size.larger")
                }
                .accessibilityLabel("Larger text")
            }
        }
        .ihGlassCard()
    }

    /// Cosmetic-only names (`ProjectionViewModel.appearanceThemeHint`'s own
    /// doc comment: "stored but DELIBERATELY NEVER APPLIED") — free-text,
    /// never a shared enum with the TV side, so this list is this view's
    /// own choice, not a protocol contract.
    private static let themeOptions = ["default", "warm", "cool", "highContrast"]

    // MARK: - 6. Congregant mirror (#1425)

    /// Only shown once a `serviceMirror` has been wired up AND the account
    /// is actually signed in (`service_broadcast` is an auth-required
    /// endpoint — offering the card to a signed-out browsing session would
    /// just produce a guaranteed `.unauthorized` on the first tap).
    @ViewBuilder
    private var mirrorCard: some View {
        if let serviceMirror, rootViewModel.sessionState.isSignedIn {
            ServiceMirrorCard(controller: serviceMirror, currentState: state)
        }
    }

    private func remoteButton(_ label: String, systemImage: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: systemImage)
                .frame(minWidth: 44, minHeight: 44)
        }
        .accessibilityLabel(label)
    }
}
