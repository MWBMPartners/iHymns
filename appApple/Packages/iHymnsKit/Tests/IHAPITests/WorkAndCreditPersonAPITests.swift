// WorkAndCreditPersonAPITests.swift
// IHAPITests
//
// ELI5: Checks the new "give me one Work" / "give me one credit person"
// calls (#1443/#1444) build the right requests and decode the right
// responses.
//
// DETAILED: `work`/`credit_person` are NEW server actions this task's
// backend survey added (`appWeb/public_html/api.php`, `SongData::getWork()`
// reused as-is / the new `SongData::getCreditPerson()`) — there is no live
// fixture to record for them yet (a brand-new endpoint has no prior
// traffic), so the decode tests below use JSON hand-modelled directly from
// those PHP functions' own `sendJson([...])`/array-literal shapes, the SAME
// "flagged, not live-verified" posture `FavoritesAndAuthAPITests.swift`'s
// header already establishes for `favorites`/`auth_me`. The sibling
// `song_request` coverage (#1447) lives in its own
// `SongRequestAPITests.swift`.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Work + CreditPerson Endpoint request building")
struct WorkAndCreditPersonEndpointTests {

    // MARK: - Work (#1443)

    @Test("Endpoint.work(slug:) is a GET with a slug query item, no auth")
    func buildsWorkEndpoint() {
        let endpoint = Endpoint.work(slug: "amazing-grace")
        #expect(endpoint.action == "work")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.httpMethod == "GET")
        #expect(endpoint.queryItems.contains { $0.name == "slug" && $0.value == "amazing-grace" })
    }

    // MARK: - Credit person (#1443, #1444)

    @Test("Endpoint.creditPerson(.slug) sends a slug query item")
    func buildsCreditPersonSlugEndpoint() {
        let endpoint = Endpoint.creditPerson(.slug("fanny-crosby"))
        #expect(endpoint.action == "credit_person")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.queryItems.count == 1)
        #expect(endpoint.queryItems[0] == (name: "slug", value: "fanny-crosby"))
    }

    @Test("Endpoint.creditPerson(.id) sends an id query item")
    func buildsCreditPersonIdEndpoint() {
        let endpoint = Endpoint.creditPerson(.id(42))
        #expect(endpoint.queryItems.count == 1)
        #expect(endpoint.queryItems[0] == (name: "id", value: "42"))
    }

    @Test("Endpoint.creditPerson(.name) sends a name query item — the ONLY lookup a tapped credit string can use")
    func buildsCreditPersonNameEndpoint() {
        let endpoint = Endpoint.creditPerson(.name("John Newton"))
        #expect(endpoint.queryItems.count == 1)
        #expect(endpoint.queryItems[0] == (name: "name", value: "John Newton"))
    }
}

@Suite("Work + CreditPerson decoding")
struct WorkAndCreditPersonDecodingTests {

    // MARK: - Work — modelled from `SongData::getWork()`'s own array literal

    @Test("Decodes a work envelope with a parent, children, members, and links")
    func decodesWorkWithFullShape() throws {
        let json = Data("""
        {
            "work": {
                "id": 7,
                "parentId": null,
                "title": "Amazing Grace",
                "slug": "amazing-grace",
                "iswc": "T-000.000.001-0",
                "notes": "A well-known hymn tune.",
                "createdAt": "2026-01-01 00:00:00",
                "updatedAt": "2026-01-02 00:00:00",
                "parent": null,
                "children": [
                    { "id": 8, "title": "Amazing Grace (Reprise)", "slug": "amazing-grace-reprise", "iswc": null }
                ],
                "members": [
                    { "songId": "MP-1008", "title": "Amazing Grace", "number": 1008, "songbook": "MP",
                      "songbookName": "Mission Praise", "isCanonical": true, "memberNote": null }
                ],
                "links": [
                    { "slug": "wikipedia", "name": "Wikipedia", "category": "information", "iconClass": "bi-wikipedia",
                      "url": "https://en.wikipedia.org/wiki/Amazing_Grace", "note": null, "verified": true, "sortOrder": 0 }
                ]
            }
        }
        """.utf8)

        let work = try APIClient.decodeWork(from: json)
        #expect(work.id == 7)
        #expect(work.title == "Amazing Grace")
        #expect(work.children.count == 1)
        #expect(work.members.count == 1)
        #expect(work.members[0].songbookAbbreviation == "MP")
        #expect(work.links.count == 1)
    }

    @Test("Decodes a work envelope whose 'children' key is entirely absent — the #1443 embedded-shape fix")
    func decodesWorkMissingChildrenKey() throws {
        // The EMBEDDED per-song shape `SongData::_worksMap()` builds for
        // `song_detail.works[]` never sets a `children` key at all (see
        // `Work.swift`'s #1443 header) — this is the exact shape that used
        // to throw `.decoding` before the fix.
        let json = Data("""
        {
            "work": {
                "id": 7, "parentId": null, "title": "Amazing Grace", "slug": "amazing-grace", "iswc": null,
                "isCanonical": true, "memberNote": null,
                "members": [], "links": []
            }
        }
        """.utf8)

        let work = try APIClient.decodeWork(from: json)
        #expect(work.id == 7)
        #expect(work.children.isEmpty)
    }

    // MARK: - Credit person — modelled from `SongData::getCreditPerson()`'s own array literal

    @Test("Decodes a full credit-person envelope with discography and links")
    func decodesCreditPersonWithFullShape() throws {
        let json = Data("""
        {
            "person": {
                "id": 3,
                "slug": "fanny-crosby",
                "name": "Fanny Crosby",
                "notes": "A prolific American hymn writer.",
                "birthPlace": "Putnam County, New York",
                "birthDate": "1820-03-24",
                "deathPlace": "Bridgeport, Connecticut",
                "deathDate": "1915-02-12",
                "isSpecialCase": false,
                "isGroup": false,
                "discography": [
                    {
                        "role": "writer",
                        "label": "As Writer",
                        "songs": [
                            { "songId": "MP-1008", "title": "Blessed Assurance", "number": 1008, "songbook": "MP", "songbookName": "Mission Praise" }
                        ]
                    }
                ],
                "links": [
                    { "slug": "wikipedia", "name": "Wikipedia", "category": "information", "iconClass": "bi-wikipedia",
                      "url": "https://en.wikipedia.org/wiki/Fanny_Crosby", "note": null, "verified": true, "sortOrder": 0 }
                ],
                "totalSongs": 1
            }
        }
        """.utf8)

        let person = try APIClient.decodeCreditPerson(from: json)
        #expect(person.id == 3)
        #expect(person.slug == "fanny-crosby")
        #expect(person.name == "Fanny Crosby")
        #expect(person.discography.count == 1)
        #expect(person.discography[0].role == "writer")
        #expect(person.discography[0].songs.count == 1)
        #expect(person.discography[0].songs[0].songId.rawValue == "MP-1008")
        #expect(person.links.count == 1)
        #expect(person.totalSongs == 1)
    }

    @Test("Decodes a name-only credit-person envelope — no registry row, id/slug are null (#1444)")
    func decodesCreditPersonWithNoRegistryRow() throws {
        // `SongData::getCreditPerson()`'s own "no row, but has credited
        // songs" fallback — the ONLY case reachable when a native client
        // taps a plain credit-name string with no `tblCreditPeople` row yet.
        let json = Data("""
        {
            "person": {
                "id": null, "slug": null, "name": "John Newton",
                "notes": null, "birthPlace": null, "birthDate": null, "deathPlace": null, "deathDate": null,
                "isSpecialCase": false, "isGroup": false,
                "discography": [
                    { "role": "writer", "label": "As Writer",
                      "songs": [ { "songId": "MP-1008", "title": "Amazing Grace", "number": 1008, "songbook": "MP", "songbookName": "Mission Praise" } ] }
                ],
                "links": [],
                "totalSongs": 1
            }
        }
        """.utf8)

        let person = try APIClient.decodeCreditPerson(from: json)
        #expect(person.id == nil)
        #expect(person.slug == nil)
        #expect(person.name == "John Newton")
        #expect(person.totalSongs == 1)
    }
}
