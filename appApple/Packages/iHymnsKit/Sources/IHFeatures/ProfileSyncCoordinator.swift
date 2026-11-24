// ProfileSyncCoordinator.swift
// IHFeatures
//
// ELI5: The small manager that owns the shared-settings sync engine, remembers
// "this site does not use it, stop asking", and waits a moment after a change so
// a slider drag is one sync instead of fifty.
//
// DETAILED: PR 3b step I4 (#2180). `AppRootViewModel` holds ONE of these (a
// stored property, because an extension cannot add one). The engine is optional
// on purpose: if the bundled schema could not be read (a packaging mistake)
// the coordinator is inert and the app behaves as if profile sync did not exist,
// instead of crashing at launch. `switchedOff` is the app's memory of a 409
// `profile_sync_disabled` / `profile_sync_not_ready` answer ("do nothing"); it
// resets when a person signs out, because the next sign-in may be on a site that
// has switched it on.
import Foundation
import IHAPI
import IHModels

extension Notification.Name {
    /// Posted (on the main queue) after the sync changed a setting on this
    /// device from the account, so screens that hold their own in-memory copy
    /// (the Settings view model) can reload it.
    static let ihProfileSettingsApplied = Notification.Name("ihProfileSettingsApplied")
}

@MainActor
public final class ProfileSyncCoordinator {
    /// How long a change waits before it is sent, so a slider drag is one change.
    static let pushDelaySeconds = 1.5

    private let engine: ProfileSyncEngine?
    private let stateStore: ProfileSyncStateStore
    public private(set) var switchedOff = false
    private var debounce: Task<Void, Never>?

    /// The real one: the bundled schema (inert if it cannot be read), `UserDefaults.standard`, and this app's version.
    public static func live() -> ProfileSyncCoordinator {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String
        return ProfileSyncCoordinator(schema: try? ProfileSchema.bundled(), appVersion: version)
    }

    /// - Parameters:
    ///   - schema: The schema to use, or `nil` to make the coordinator inert.
    ///   - defaults: Where settings and the sync notebook live (`.standard` for the app).
    public init(schema: ProfileSchema?, defaults: UserDefaults = .standard, appVersion: String? = nil) {
        let store = ProfileSyncStateStore(defaults: defaults)
        stateStore = store
        engine = schema.map {
            ProfileSyncEngine(schema: $0, local: ProfileLocalSettings(defaults: defaults), stateStore: store, appVersion: appVersion)
        }
    }

    /// Runs one sync for `userId` through `apiClient`. Does nothing when inert or switched off.
    @discardableResult
    func sync(userId: Int, apiClient: APIClient) async -> ProfileSyncOutcome {
        guard let engine, !switchedOff else { return .off }
        let transport = ProfileSyncTransport(
            read: { cursor in try await apiClient.profileSyncRead(cursor: cursor) },
            send: { request in try await apiClient.profileSyncSend(request) }
        )
        let outcome = await engine.sync(userId: userId, transport: transport)
        switch outcome {
        case .off:
            switchedOff = true
        case .done(let changedLocal) where changedLocal:
            NotificationCenter.default.post(name: .ihProfileSettingsApplied, object: nil)
        default:
            break
        }
        return outcome
    }

    /// Runs `action` after a short pause, replacing any pause already waiting.
    func schedule(_ action: @escaping @MainActor () async -> Void) {
        debounce?.cancel()
        debounce = Task { [weak self] in
            try? await Task.sleep(for: .seconds(Self.pushDelaySeconds))
            guard !Task.isCancelled, self != nil else { return }
            await action()
        }
    }

    /// Forgets the "switched off" memory and `userId`'s notebook (sign-out).
    func forget(userId: Int?) {
        debounce?.cancel()
        switchedOff = false
        if let userId { stateStore.clear(userId: userId) }
    }
}
