// AuthDeviceAPITests.swift
// IHAPITests
//
// ELI5: Checks the five "one sign-in per device" calls build the right
// requests, decode the right answers, and tell their refusals apart by the
// server's machine `reason` word; and that the device details ride along
// with an ordinary sign-in.
//
// DETAILED: PR 3b step I2b (#2180). Every response shape is modelled from the
// live `api.php` cases and `includes/device_signin.php` (read 2026-11-24), the
// same "no live test account, model from source" caveat the other API test
// files state. Request bodies are checked at the request-building level
// (`endpoint.httpBody`), because a body captured through `MockURLProtocol` is
// not reliable (see `AppRootViewModelSetlistsTests`' note); the headers and
// the URL ARE checked end to end. Mirrors `SetlistsAPITests.swift`'s layout.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Device sign-in endpoint request building")
struct AuthDeviceEndpointTests {

    private let device = DeviceSignInDetails(installId: "1234abcd-0000-4000-8000-123456789abc", deviceName: "iPhone", appVersion: "1.2.3")

    private func json(_ endpoint: Endpoint) throws -> [String: Any] {
        let body = try #require(endpoint.httpBody)
        return try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
    }

    @Test("auth_device_enrol: POST, needs the (shared) bearer sign-in, captures the refusal reason, sends the install details")
    func buildsEnrol() throws {
        let endpoint = try Endpoint.authDeviceEnrol(device: device)
        #expect(endpoint.action == "auth_device_enrol")
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.capturesRefusalReason == true)
        let body = try json(endpoint)
        #expect(body["deviceInstallId"] as? String == "1234abcd-0000-4000-8000-123456789abc")
        #expect(body["platform"] as? String == "apple")
        #expect(body["deviceName"] as? String == "iPhone")
        #expect(body["appVersion"] as? String == "1.2.3")
        // The server's default is "make a key", so the field is left out unless it is false.
        #expect(body.keys.contains("createEnrolKey") == false)
    }

    @Test("auth_device_enrol says createEnrolKey: false only when the app declines a key")
    func buildsEnrolWithoutKey() throws {
        let body = try json(try Endpoint.authDeviceEnrol(device: device, createEnrolKey: false))
        #expect(body["createEnrolKey"] as? Bool == false)
    }

    @Test("auth_enrol_key_redeem: POST, NO bearer (the key is the proof), carries the key and the install details")
    func buildsRedeem() throws {
        let endpoint = try Endpoint.authEnrolKeyRedeem(enrolKey: "ihek1_" + String(repeating: "a", count: 64), device: device)
        #expect(endpoint.action == "auth_enrol_key_redeem")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.capturesRefusalReason == true)
        let body = try json(endpoint)
        #expect((body["enrolKey"] as? String)?.hasPrefix("ihek1_") == true)
        #expect(body["deviceInstallId"] as? String == device.installId)
        #expect(body["platform"] as? String == "apple")
    }

    @Test("auth_enrol_key_create, auth_enrol_keys_revoke: signed-in POSTs with no body")
    func buildsKeyCreateAndRevoke() {
        for endpoint in [Endpoint.authEnrolKeyCreate, Endpoint.authEnrolKeysRevoke] {
            #expect(endpoint.httpMethod == "POST")
            #expect(endpoint.requiresAuth == true)
            #expect(endpoint.httpBody == nil)
            #expect(endpoint.capturesRefusalReason == true)
        }
        #expect(Endpoint.authEnrolKeyCreate.action == "auth_enrol_key_create")
        #expect(Endpoint.authEnrolKeysRevoke.action == "auth_enrol_keys_revoke")
    }

    @Test("auth_signout_everywhere: signed-in POST, keepCurrent only when true")
    func buildsSignOutEverywhere() throws {
        let plain = try Endpoint.authSignOutEverywhere()
        #expect(plain.action == "auth_signout_everywhere")
        #expect(plain.requiresAuth == true)
        #expect(try json(plain).keys.contains("keepCurrent") == false)
        #expect(try json(try Endpoint.authSignOutEverywhere(keepCurrent: true))["keepCurrent"] as? Bool == true)
    }

    @Test("A plain sign-in sends the device details when given, and is unchanged when not")
    func loginBodyCarriesDeviceDetails() throws {
        let withDevice = try json(try Endpoint.authLogin(username: "jane", password: "pw", device: device))
        #expect(withDevice["deviceInstallId"] as? String == device.installId)
        #expect(withDevice["platform"] as? String == "apple")
        #expect(withDevice["deviceName"] as? String == "iPhone")
        #expect(withDevice["appVersion"] as? String == "1.2.3")

        let without = try json(try Endpoint.authLogin(username: "jane", password: "pw"))
        for key in ["deviceInstallId", "platform", "deviceName", "appVersion"] {
            #expect(without.keys.contains(key) == false)
        }
        #expect(Set(without.keys) == ["username", "password"])
    }

    @Test("The email-code sign-in carries the device details the same way")
    func emailVerifyBodyCarriesDeviceDetails() throws {
        let withDevice = try json(try Endpoint.authEmailLoginVerify(email: "a@b.co", code: "123456", device: device))
        #expect(withDevice["deviceInstallId"] as? String == device.installId)
        let without = try json(try Endpoint.authEmailLoginVerify(email: "a@b.co", code: "123456"))
        #expect(without.keys.contains("deviceInstallId") == false)
    }
}

@Suite("Device details")
struct DeviceSignInDetailsTests {

    @Test("The device name is a plain model word")
    func genericNames() {
        #expect(DeviceSignInDetails.genericDeviceName(forMachine: "iPhone16,1") == "iPhone")
        #expect(DeviceSignInDetails.genericDeviceName(forMachine: "iPod9,1") == "iPhone")
        #expect(DeviceSignInDetails.genericDeviceName(forMachine: "iPad14,3") == "iPad")
        #expect(DeviceSignInDetails.genericDeviceName(forMachine: "arm64") == "Apple device")
        #expect(DeviceSignInDetails.genericDeviceName(forMachine: "") == "Apple device")
    }

    @Test("An app version the server would drop is left out here instead")
    func appVersionCleaning() {
        #expect(DeviceSignInDetails.sanitizedAppVersion("1.4.2") == "1.4.2")
        #expect(DeviceSignInDetails.sanitizedAppVersion(" 1.4.2-beta.1 ") == "1.4.2-beta.1")
        #expect(DeviceSignInDetails.sanitizedAppVersion(nil) == nil)
        #expect(DeviceSignInDetails.sanitizedAppVersion("") == nil)
        #expect(DeviceSignInDetails.sanitizedAppVersion("1.0 (build 7)") == nil)
        #expect(DeviceSignInDetails.sanitizedAppVersion(String(repeating: "1", count: 21)) == nil)
    }

    @Test("The platform is always apple")
    func platformIsApple() {
        #expect(DeviceSignInDetails(installId: "abcdefgh", deviceName: nil, appVersion: nil).platform == "apple")
    }
}

@Suite("Device sign-in refusal classification")
struct AuthDeviceRefusalTests {

    private func v2(_ reason: String, code: String = "http_409") -> Data {
        Data(#"{"ok":false,"error":{"code":"\#(code)","message":"A long human sentence.","reason":"\#(reason)"}}"#.utf8)
    }

    @Test("With capturingReason, a 409 or 403 carries the machine reason word")
    func capturesReason() {
        #expect(APIClient.classifyMachineRefusal(httpStatus: 409, body: v2("already_enrolled"), capturingReason: true)
                == .server(status: 409, message: "already_enrolled"))
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: v2("install_signed_out", code: "http_403"), capturingReason: true)
                == .server(status: 403, message: "install_signed_out"))
        #expect(APIClient.classifyMachineRefusal(httpStatus: 400, body: v2("invalid_install_id", code: "http_400"), capturingReason: true)
                == .server(status: 400, message: "invalid_install_id"))
    }

    @Test("Without capturingReason (every existing call) the same body is left to the generic classifier")
    func defaultIsUnchanged() {
        #expect(APIClient.classifyMachineRefusal(httpStatus: 409, body: v2("already_enrolled")) == nil)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: v2("install_signed_out", code: "http_403")) == nil)
    }

    @Test("401, 429 and 503 keep their own cases even when capturing")
    func statusesWithOwnCasesAreLeftAlone() {
        for status in [401, 423, 429, 503] {
            #expect(APIClient.classifyMachineRefusal(httpStatus: status, body: v2("rate_limited"), capturingReason: true) == nil)
        }
    }

    @Test("A CAPTCHA refusal is still a CAPTCHA refusal when capturing")
    func captchaStillWins() {
        let body = Data(#"{"ok":false,"error":{"code":"captcha_required","message":"x","reason":"captcha_required"}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body, capturingReason: true) == .captchaRequired)
    }

    @Test("A sentence is never mistaken for a reason word, and a flat (v1) body works")
    func rejectsProseAcceptsFlat() {
        let prose = Data(#"{"ok":false,"error":{"code":"http_409","message":"x","reason":"This device is already set up."}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 409, body: prose, capturingReason: true) == nil)
        let flat = Data(#"{"error":"x","reason":"device_signin_disabled"}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 409, body: flat, capturingReason: true)
                == .server(status: 409, message: "device_signin_disabled"))
        #expect(APIClient.classifyMachineRefusal(httpStatus: 409, body: Data("<html>".utf8), capturingReason: true) == nil)
    }
}

@Suite("Device sign-in decoding")
struct AuthDeviceDecodingTests {

    @Test("An enrol answer decodes the session plus the device id, expiry and the one-time key")
    func decodesEnrol() throws {
        let json = Data(#"""
        {"token":"\#(String(repeating: "a", count: 64))","user":{"id":7,"username":"jane","display_name":"Jane","role":"user","avatar_service":null},
         "device":{"id":"3fa9c0de"},"expiresAt":"2026-12-24 09:00:00",
         "enrolKey":"ihek1_\#(String(repeating: "b", count: 64))","enrolKeyExpiresAt":"2027-05-23 09:00:00"}
        """#.utf8)
        let result = try APIClient.decodeDeviceSignInResult(from: json)
        #expect(result.session.user.id == 7)
        #expect(result.session.token.count == 64)
        #expect(result.deviceId == "3fa9c0de")
        #expect(result.enrolKey?.hasPrefix("ihek1_") == true)
    }

    @Test("A redeem answer has no key and still decodes")
    func decodesRedeem() throws {
        let json = Data(#"""
        {"token":"tok","user":{"id":7,"username":"jane","display_name":"Jane","role":"user"},"device":{"id":"x"},"expiresAt":"2026-12-24 09:00:00"}
        """#.utf8)
        let result = try APIClient.decodeDeviceSignInResult(from: json)
        #expect(result.enrolKey == nil)
        #expect(result.enrolKeyExpiresAt == nil)
    }

    @Test("A key-create answer needs a non-empty key")
    func decodesKeyIssue() throws {
        let issue = try APIClient.decodeEnrolKeyIssue(from: Data(#"{"enrolKey":"ihek1_abc","expiresAt":"2027-05-23 09:00:00"}"#.utf8))
        #expect(issue.enrolKey == "ihek1_abc")
        #expect(throws: APIError.decoding) { _ = try APIClient.decodeEnrolKeyIssue(from: Data(#"{"enrolKey":""}"#.utf8)) }
        #expect(throws: APIError.decoding) { _ = try APIClient.decodeEnrolKeyIssue(from: Data(#"{}"#.utf8)) }
    }

    @Test("The revoke and sign-out-everywhere answers decode")
    func decodesCounts() throws {
        #expect(try APIClient.decodeEnrolKeysRevoked(from: Data(#"{"revoked":2}"#.utf8)) == 2)
        let all = try APIClient.decodeSignOutEverywhere(from: Data(#"{"tokensRevoked":3,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8))
        #expect(all == SignOutEverywhereResult(tokensRevoked: 3, enrolKeysRevoked: 1, keptCurrent: false))
    }

    @Test("app_status reads deviceSignin and profileSync, and a missing key means false")
    func appStatusFlags() throws {
        let enabled = try APIClient.decodeAppStatus(from: Data(#"{"deviceSignin":true,"profileSync":true}"#.utf8))
        #expect(enabled.deviceSignin && enabled.profileSync)
        let missing = try APIClient.decodeAppStatus(from: Data(#"{"maintenance":false}"#.utf8))
        #expect(missing.deviceSignin == false && missing.profileSync == false)
        #expect(missing.captcha == nil)
    }
}

@Suite("Device sign-in networked calls (mocked transport)", .serialized)
struct AuthDeviceNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private let device = DeviceSignInDetails(installId: "1234abcd-0000-4000-8000-123456789abc", deviceName: "iPhone", appVersion: "1.2.3")

    @Test("authDeviceEnrol sends the bearer header, decodes the v2 envelope, and surfaces a 409 reason")
    func enrolEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let seen = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seen.set(request)
                let body = Data(#"{"ok":true,"data":{"token":"newtoken","user":{"id":1,"username":"j","display_name":"J","role":"user"},"device":{"id":"abc"},"expiresAt":"2026-12-24 09:00:00"}}"#.utf8)
                return (Self.response(for: request, status: 200), body)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "shared-token")
            let result = try await client.authDeviceEnrol(device: device)

            #expect(result.session.token == "newtoken")
            #expect(seen.current?.url?.query?.contains("action=auth_device_enrol") == true)
            #expect(seen.current?.httpMethod == "POST")
            #expect(seen.current?.value(forHTTPHeaderField: "Authorization") == "Bearer shared-token")
            // A native app must not look like a browser: no Origin header is ever added.
            #expect(seen.current?.value(forHTTPHeaderField: "Origin") == nil)
            #expect(seen.current?.value(forHTTPHeaderField: "X-Requested-With") == "XMLHttpRequest")

            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 409),
                 Data(#"{"ok":false,"error":{"code":"http_409","message":"x","reason":"already_enrolled"}}"#.utf8))
            }
            await #expect(throws: APIError.server(status: 409, message: "already_enrolled")) {
                _ = try await client.authDeviceEnrol(device: device)
            }
        }
    }

    @Test("authEnrolKeyRedeem sends no Authorization header, and every unusable key is .unauthorized")
    func redeemEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let seen = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seen.set(request)
                return (Self.response(for: request, status: 401),
                        Data(#"{"ok":false,"error":{"code":"http_401","message":"x","reason":"enrol_key_invalid"}}"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            // A bearer token is set on the client, yet must NOT be attached: the key is the proof.
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "should-not-be-sent")
            await #expect(throws: APIError.unauthorized) {
                _ = try await client.authEnrolKeyRedeem(enrolKey: "ihek1_" + String(repeating: "c", count: 64), device: device)
            }
            #expect(seen.current?.value(forHTTPHeaderField: "Authorization") == nil)
        }
    }

    @Test("authSignOutEverywhere decodes the counts; authEnrolKeysRevoke decodes the number")
    func signOutEverywhereEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                let action = request.url?.query ?? ""
                let body = action.contains("auth_signout_everywhere")
                    ? #"{"ok":true,"data":{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}}"#
                    : #"{"ok":true,"data":{"revoked":1}}"#
                return (Self.response(for: request, status: 200), Data(body.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "t")
            let everywhere = try await client.authSignOutEverywhere()
            let revoked = try await client.authEnrolKeysRevoke()
            #expect(everywhere.tokensRevoked == 2)
            #expect(revoked == 1)
        }
    }
}
