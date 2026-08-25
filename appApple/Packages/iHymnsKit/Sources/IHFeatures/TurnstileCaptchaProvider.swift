// TurnstileCaptchaProvider.swift
// IHFeatures
//
// ELI5: The actual "how to draw Cloudflare's puzzle" recipe —
// `CaptchaChallenge.swift`'s empty socket, filled in for the ONE provider
// this app ships (owner decision, 2026-08-25: "Provider = Cloudflare
// Turnstile").
//
// DETAILED: `.claude/captcha-native-and-outage-plan.md` §2.6: Turnstile
// ships NO native SDK on ANY Apple platform (unlike hCaptcha, whose SPM SDK
// was the plan's one non-WebView alternative) — the ONLY way to render it
// natively is a `WKWebView` hosting a minimal HTML page that loads the
// widget's own script and bridges its solved token back into Swift.
//
// `#if canImport(WebKit)` guards the ENTIRE web-hosting implementation
// below — WebKit does not exist on tvOS or watchOS (plan §2.6/D-N2: no
// `WKWebView`, and no provider ships a native SDK for either platform
// either), so `isSupportedOnThisPlatform` is `false` there and
// `makeChallengeView` returns a clear, worded "not available" message
// instead of a mystery build failure OR a silently-blank screen — the SAME
// `#if canImport(PDFKit)` / platform-conditional-representable shape
// `SheetMusicView.swift` already establishes for the identical "a system
// framework doesn't exist on every platform this package targets" problem.
//
// SECURITY NOTE (why every interpolated value below is escaped): `config`
// arrives over the network from THIS APP'S OWN server, so this is
// defence-in-depth rather than a live threat model — but `scriptUrl`/
// `siteKey`/`renderGlobal` are still untrusted-by-construction bytes being
// embedded into HTML/JS source, and `renderGlobal` specifically is
// interpolated into CODE position (`window.<renderGlobal>`), not just a
// quoted string — so it is validated as a bare JS identifier before use,
// never interpolated raw. `scriptUrl` is validated as an `https://` URL.
// `siteKey` is embedded via a JSON-encoded string literal (valid JS string
// syntax) with an extra `</` guard against a value that happens to contain
// a literal `</script>`. A malformed/malicious config simply fails these
// checks and `makeChallengeView` degrades to "not available" — it never
// loads a page that could execute anything the config didn't intend.
import Foundation
import IHModels
import SwiftUI
#if canImport(WebKit)
// `@preconcurrency` — `WKScriptMessageHandler.userContentController(_:didReceive:)`
// is a plain ObjC-bridged, non-`@MainActor`-annotated protocol requirement;
// `Coordinator` below is `@MainActor` (it touches `WKWebView`, which is
// UI-thread-only), and WebKit's own module has not always been fully
// audited for Swift 6's strictest concurrency checking. Mirrors the
// identical, already-verified fix `NowSingingActivityController.swift`
// applies to `import ActivityKit` for the same class of "SDK conformance
// exists but the newest checker still flags it" friction — see that file's
// own comment for the "confirmed this actually clears the diagnostic"
// note, which applies here by the same reasoning.
@preconcurrency import WebKit
#endif

/// Cloudflare Turnstile, hosted in a `WKWebView`. See this file's header
/// for the full design.
///
/// ELI5: "Draw Cloudflare's puzzle, and tell me the answer."
///
/// DETAILED: A `final class` (reference type, required by
/// `CaptchaChallengeProviding`'s `AnyObject` constraint) because `reset()`
/// needs to reach whichever `WKWebView` `makeChallengeView` most recently
/// created — held as a WEAK reference inside `coordinatorBox` so this
/// provider instance never keeps a torn-down login screen's web view alive.
@MainActor
public final class TurnstileCaptchaProvider: CaptchaChallengeProviding {
    public init() {}

    public let providerKey = "turnstile"

    public var isSupportedOnThisPlatform: Bool {
        #if canImport(WebKit)
        true
        #else
        false
        #endif
    }

    public func makeChallengeView(config: CaptchaConfig, onToken: @escaping (String) -> Void) -> AnyView {
        #if canImport(WebKit)
        AnyView(TurnstileWebView(config: config, coordinatorBox: coordinatorBox, onToken: onToken))
        #else
        AnyView(Self.unsupportedMessage)
        #endif
    }

    public func reset() {
        #if canImport(WebKit)
        // A full page reload — not a JS `turnstile.reset(widgetId)` call —
        // is deliberately the simplest, most robust "invalidate any solved
        // state": it re-runs the widget script from scratch with no
        // dependency on the provider's own reset API existing or behaving
        // identically across a future non-Turnstile conformance sharing
        // this same coordinator shape (plan §2.5's "invalidate any solved
        // state" contract cares about the OUTCOME, not the mechanism).
        coordinatorBox.coordinator?.reloadWidget()
        #endif
    }

    #if canImport(WebKit)
    /// Holds a WEAK reference to whichever `TurnstileWebView.Coordinator`
    /// is currently on screen, so `reset()` (called by `LoginView` after
    /// EVERY failed submit) can reach it — see this type's own doc comment.
    let coordinatorBox = TurnstileCoordinatorBox()
    #endif

    /// The tvOS/watchOS (and any other platform without `WebKit`) degrade:
    /// a clear, worded message rather than a mystery failure (plan §2.6/
    /// D-N2) — never attempts to fake a challenge that cannot actually
    /// render there.
    private static var unsupportedMessage: some View {
        ContentUnavailableView(
            "Verification Not Available",
            systemImage: "checkmark.shield.slash",
            description: Text("This device can't show the verification challenge. Please sign in from an iPhone, iPad, Mac, or Vision Pro instead.")
        )
    }
}

#if canImport(WebKit)
/// A plain, `@unchecked Sendable` box holding a weak `Coordinator`
/// reference — its own tiny type (rather than a bare `weak var` directly on
/// `TurnstileCaptchaProvider`) purely so `TurnstileWebView`'s
/// `makeUIView`/`makeNSView` (which run with a `Context`, not direct access
/// to the provider instance) and `TurnstileCaptchaProvider.reset()` (which
/// DOES hold the provider instance) can share the exact same mutable slot.
/// `@unchecked Sendable`: every mutation happens on the main actor (SwiftUI
/// representable callbacks and `reset()` are both `@MainActor`-isolated by
/// construction), so this is sound despite the compiler being unable to
/// prove it structurally.
final class TurnstileCoordinatorBox: @unchecked Sendable {
    // ELI5: only this file may touch this slot, because what it holds is only
    // visible inside this file.
    // DETAILED: `fileprivate` is REQUIRED — `TurnstileWebView` is `private` at
    // file scope in BOTH platform arms, so an `internal` property naming its
    // nested `Coordinator` fails with "property must be declared fileprivate
    // because its type uses a private type". Every user of it is in this file.
    fileprivate weak var coordinator: TurnstileWebView.Coordinator?
}

/// The macOS `WKWebView` wrapper — `NSViewRepresentable`, since AppKit (not
/// UIKit) is macOS's native view-hosting bridge for SwiftUI, mirroring
/// `SheetMusicView.swift`'s `PDFKitRepresentableView` split.
#if os(macOS)
private struct TurnstileWebView: NSViewRepresentable {
    let config: CaptchaConfig
    let coordinatorBox: TurnstileCoordinatorBox
    let onToken: (String) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(onToken: onToken)
    }

    func makeNSView(context: Context) -> WKWebView {
        let webView = Self.makeConfiguredWebView(coordinator: context.coordinator)
        context.coordinator.load(into: webView, config: config)
        coordinatorBox.coordinator = context.coordinator
        return webView
    }

    func updateNSView(_ nsView: WKWebView, context: Context) {
        // The challenge is loaded ONCE in `makeNSView` — `config` doesn't
        // change for the lifetime of one login screen, so there is nothing
        // to re-apply here. Reloading is `reset()`'s job (via the
        // coordinator, not this SwiftUI update path).
    }

    static func dismantleNSView(_ nsView: WKWebView, coordinator: Coordinator) {
        coordinator.tearDown()
    }
}
#else
/// The iOS/iPadOS/visionOS `WKWebView` wrapper — `UIViewRepresentable`.
private struct TurnstileWebView: UIViewRepresentable {
    let config: CaptchaConfig
    let coordinatorBox: TurnstileCoordinatorBox
    let onToken: (String) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(onToken: onToken)
    }

    func makeUIView(context: Context) -> WKWebView {
        let webView = Self.makeConfiguredWebView(coordinator: context.coordinator)
        context.coordinator.load(into: webView, config: config)
        coordinatorBox.coordinator = context.coordinator
        return webView
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {
        // See the macOS `updateNSView`'s matching comment above.
    }

    static func dismantleUIView(_ uiView: WKWebView, coordinator: Coordinator) {
        coordinator.tearDown()
    }
}
#endif

extension TurnstileWebView {
    /// The `postMessage` handler name the loaded page's JS posts a solved
    /// token to — one Swift-side literal, matched exactly in
    /// `Coordinator.html(for:)`'s generated `<script>` below.
    static let messageHandlerName = "captchaBridge"

    /// Builds a `WKWebView` with the JS↔Swift message-handler bridge
    /// installed — shared by both platform-specific `makeNSView`/
    /// `makeUIView` above so the configuration logic itself is written
    /// exactly once.
    static func makeConfiguredWebView(coordinator: Coordinator) -> WKWebView {
        let contentController = WKUserContentController()
        contentController.add(coordinator, name: messageHandlerName)
        let configuration = WKWebViewConfiguration()
        configuration.userContentController = contentController
        let webView = WKWebView(frame: .zero, configuration: configuration)
        // ELI5: only the iPhone/iPad/Vision view has a "let the background
        // show through" switch; the Mac one doesn't, so we only flip it here.
        // DETAILED: keep `isOpaque` INSIDE this guard — `WKWebView` is a
        // `UIView` on iOS/visionOS (settable) but an `NSView` on macOS, where
        // `isOpaque` is GET-ONLY ("cannot assign to property"), and this helper
        // is shared by BOTH representables. Never hoist it out; never use KVC
        // to force macOS transparency (the widget paints its own background).
        #if os(iOS) || os(visionOS)
        webView.isOpaque = false
        webView.backgroundColor = .clear
        webView.scrollView.backgroundColor = .clear
        #endif
        coordinator.webView = webView
        return webView
    }

    /// Bridges the loaded page's solved-token callback into Swift, and lets
    /// `TurnstileCaptchaProvider.reset()` reach the live web view.
    ///
    /// ELI5: "The translator standing between the web puzzle and the rest
    /// of the app."
    @MainActor
    final class Coordinator: NSObject, WKScriptMessageHandler {
        private let onToken: (String) -> Void
        fileprivate weak var webView: WKWebView?

        init(onToken: @escaping (String) -> Void) {
            self.onToken = onToken
        }

        /// Loads the minimal Turnstile HTML page into `webView`.
        ///
        /// DETAILED: `baseURL` is pinned to the WIDGET SCRIPT's own origin
        /// (e.g. `https://challenges.cloudflare.com`) rather than left
        /// `nil` (which presents as `about:blank`) — Turnstile validates
        /// the EMBEDDING document's origin against the sitekey's registered
        /// hostname allow-list (plan §2.6's documented "baseURL-domain
        /// technique"); an `about:blank` origin fails that check for a
        /// sitekey registered to the real iHymns domain. This is the ONE
        /// Turnstile-specific quirk this file encodes — every literal it
        /// embeds (`scriptUrl`/`siteKey`/`renderGlobal`) still arrives
        /// entirely from `config`, never a hand-typed Cloudflare hostname.
        func load(into webView: WKWebView, config: CaptchaConfig) {
            guard let html = TurnstileWebView.html(for: config, messageHandlerName: TurnstileWebView.messageHandlerName) else {
                // Malformed config (an invalid script URL, or a
                // `renderGlobal` that isn't a safe JS identifier — see this
                // file's header) — load nothing rather than a broken/unsafe
                // page. The widget then simply never calls `onToken`, and
                // the caller submits token-less, exactly the "unresolvable"
                // degrade `CaptchaChallengeRegistry`'s own doc comment
                // describes for a provider with no usable conformance.
                return
            }
            webView.loadHTMLString(html, baseURL: URL(string: config.scriptUrl))
        }

        func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
            guard
                message.name == TurnstileWebView.messageHandlerName,
                let token = message.body as? String,
                !token.isEmpty
            else { return }
            onToken(token)
        }

        /// Reloads the whole page — see `TurnstileCaptchaProvider.reset()`'s
        /// doc comment for why a reload (not a JS reset call) is the
        /// chosen mechanism.
        func reloadWidget() {
            webView?.reload()
        }

        /// Removes the message handler on teardown (`dismantleNSView`/
        /// `dismantleUIView`) — `WKUserContentController.add(_:name:)`
        /// retains its handler strongly, and this coordinator's own
        /// lifetime is otherwise owned entirely by SwiftUI's `Context`, so
        /// this is the standard WebKit-recommended belt-and-braces cleanup
        /// (Apple docs: https://developer.apple.com/documentation/webkit/wkusercontentcontroller/1537172-removescriptmessagehandler)
        /// rather than a fix for an observed leak.
        func tearDown() {
            webView?.configuration.userContentController.removeScriptMessageHandler(forName: TurnstileWebView.messageHandlerName)
        }
    }
}

extension TurnstileWebView {
    /// Builds the minimal HTML page: an empty container `<div>`, the
    /// widget's own script tag, and a tiny inline script that calls the
    /// EXPLICIT `window[renderGlobal].render(el, {sitekey, callback})` API
    /// every v1 provider shares (`includes/captcha.php`'s own doc-block) —
    /// deliberately the EXPLICIT render call, not an auto-rendering
    /// `data-sitekey` div, so this bridge needs no provider-specific CSS
    /// class name (which `CaptchaConfig` doesn't carry — only
    /// `scriptUrl`/`siteKey`/`renderGlobal`/`field` do) and stays driven
    /// ENTIRELY by config the server already sent, matching the reference
    /// web client's OWN `mountCaptcha()` (`js/modules/captcha-widget.js`),
    /// which resolves the identical `g.render(el, {sitekey})` call from
    /// `window[_config.renderGlobal]`.
    ///
    /// Returns `nil` when `config` fails validation (an unsafe
    /// `renderGlobal`, or a non-`https` `scriptUrl`) — see this file's
    /// header's "SECURITY NOTE" for why each interpolated value is
    /// checked/escaped before being embedded.
    static func html(for config: CaptchaConfig, messageHandlerName: String) -> String? {
        guard let scriptURL = URL(string: config.scriptUrl), scriptURL.scheme?.lowercased() == "https" else {
            return nil
        }
        guard isSafeJSIdentifier(config.renderGlobal) else {
            return nil
        }
        guard let siteKeyLiteral = try? jsStringLiteral(config.siteKey) else {
            return nil
        }
        let scriptSrc = htmlAttributeEscaped(config.scriptUrl)
        let renderGlobal = config.renderGlobal

        return """
        <!DOCTYPE html>
        <html>
        <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
        html, body { margin: 0; padding: 0; background: transparent; }
        #ihymns-captcha { display: flex; align-items: center; justify-content: center; min-height: 70px; }
        </style>
        </head>
        <body>
        <div id="ihymns-captcha"></div>
        <script src="\(scriptSrc)" async defer onload="__ihymnsRenderCaptcha()"></script>
        <script>
        function __ihymnsRenderCaptcha() {
            var api = window.\(renderGlobal);
            if (!api || typeof api.render !== 'function') { return; }
            api.render('#ihymns-captcha', {
                sitekey: \(siteKeyLiteral),
                callback: function (token) {
                    window.webkit.messageHandlers.\(messageHandlerName).postMessage(token);
                }
            });
        }
        </script>
        </body>
        </html>
        """
    }

    /// Whether `value` is safe to interpolate into CODE position
    /// (`window.<value>`) — a bare JS identifier, nothing else. Guards
    /// `renderGlobal` specifically (the ONE config field embedded outside a
    /// quoted-string/attribute context).
    private static func isSafeJSIdentifier(_ value: String) -> Bool {
        guard let first = value.unicodeScalars.first, CharacterSet.letters.contains(first) || first == "_" else {
            return false
        }
        return value.unicodeScalars.allSatisfy { CharacterSet.alphanumerics.contains($0) || $0 == "_" }
    }

    /// Escapes `value` for safe use inside a double-quoted HTML attribute
    /// (`src="…"`).
    private static func htmlAttributeEscaped(_ value: String) -> String {
        value
            .replacingOccurrences(of: "&", with: "&amp;")
            .replacingOccurrences(of: "\"", with: "&quot;")
            .replacingOccurrences(of: "<", with: "&lt;")
            .replacingOccurrences(of: ">", with: "&gt;")
    }

    /// Safely embeds `value` as a JS string literal inside a `<script>`
    /// block. JSON string syntax IS valid JS string-literal syntax (both
    /// double-quoted, with the same escape set), so encoding through
    /// `JSONEncoder` gets correct escaping for free
    /// (https://www.json.org/json-en.html); the one extra step
    /// (`"</" → "<\/"`) stops a value containing a literal `</script>` from
    /// prematurely closing the surrounding tag — HTML parsing, not JSON
    /// parsing, is what would close it, so JSON encoding alone doesn't
    /// guard against this.
    private static func jsStringLiteral(_ value: String) throws -> String {
        let data = try JSONEncoder().encode(value)
        let json = String(decoding: data, as: UTF8.self)
        return json.replacingOccurrences(of: "</", with: "<\\/")
    }
}
#endif
