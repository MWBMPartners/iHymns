// APIErrorAccountDeleteMappingTests.swift
// IHFeaturesTests
//
// ELI5: Checks that each failure `AccountDeleteView` can hit turns into the
// EXACT copy this task's brief calls out — 401 → "re-authentication
// failed," 429 → "too many attempts," 409 → "transfer Global Admin first" —
// and that every OTHER status falls through to the generic
// `userFacingMessage` copy unchanged.
//
// DETAILED: #1477 (App Review §5.1.1(v)). Pure, synchronous, zero-setup
// mapping logic (`APIError+UserFacing.swift`'s `accountDeleteUserFacingMessage`)
// — no mock transport or view model needed, unlike every OTHER test in this
// target.
import Testing
@testable import IHAPI
@testable import IHFeatures

@Suite("APIError.accountDeleteUserFacingMessage (#1477)")
struct APIErrorAccountDeleteMappingTests {

    @Test(".unauthorized maps to the re-authentication-failed copy")
    func unauthorizedMapsToReauthFailed() {
        #expect(APIError.unauthorized.accountDeleteUserFacingMessage == "Re-authentication failed. Please try again.")
    }

    @Test(".rateLimited maps to the too-many-attempts copy")
    func rateLimitedMapsToTooManyAttempts() {
        #expect(APIError.rateLimited(retryAfterSeconds: nil).accountDeleteUserFacingMessage == "Too many attempts. Please try again later.")
        // The copy doesn't vary by the server's Retry-After hint — confirm a
        // non-nil value maps identically.
        #expect(APIError.rateLimited(retryAfterSeconds: 60).accountDeleteUserFacingMessage == "Too many attempts. Please try again later.")
    }

    @Test(".server(status: 409, _) maps to the transfer-Global-Admin copy")
    func status409MapsToTransferGlobalAdmin() {
        #expect(APIError.server(status: 409, message: nil).accountDeleteUserFacingMessage == "Transfer Global Admin to another account first.")
        // The 409 mapping ignores the message payload too, mirroring every
        // other `.server` case's own doc comment ("every OTHER `.server`
        // status falls through to the generic copy").
        #expect(APIError.server(status: 409, message: "ignored").accountDeleteUserFacingMessage == "Transfer Global Admin to another account first.")
    }

    @Test("Every OTHER .server status falls through to the generic userFacingMessage")
    func otherServerStatusesFallThroughToGeneric() {
        let error = APIError.server(status: 400, message: nil)
        #expect(error.accountDeleteUserFacingMessage == error.userFacingMessage)
    }

    @Test(".offline/.maintenance/.decoding/.accountLocked all fall through to the generic userFacingMessage")
    func nonSpecialCasedErrorsFallThroughToGeneric() {
        let errors: [APIError] = [.offline, .maintenance(retryAfterSeconds: nil), .decoding, .accountLocked(retryAfterSeconds: nil)]
        for error in errors {
            #expect(error.accountDeleteUserFacingMessage == error.userFacingMessage)
        }
    }
}
