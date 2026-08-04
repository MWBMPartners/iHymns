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

    // MARK: - Credit person / musician (#1443, #1444, #1752 Slice D)

    @Test("Endpoint.creditPerson(.slug) sends a slug query item, action=musician (the #1741 P2-B canonical action, #1752 Slice D)")
    func buildsCreditPersonSlugEndpoint() {
        let endpoint = Endpoint.creditPerson(.slug("fanny-crosby"))
        #expect(endpoint.action == "musician")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.queryItems.count == 1)
        #expect(endpoint.queryItems[0] == (name: "slug", value: "fanny-crosby"))
    }

    @Test("Endpoint.creditPerson(.id) sends an id query item, action=musician")
    func buildsCreditPersonIdEndpoint() {
        let endpoint = Endpoint.creditPerson(.id(42))
        #expect(endpoint.action == "musician")
        #expect(endpoint.queryItems.count == 1)
        #expect(endpoint.queryItems[0] == (name: "id", value: "42"))
    }

    @Test("Endpoint.creditPerson(.name) sends a name query item, action=musician — the ONLY lookup a tapped credit string can use")
    func buildsCreditPersonNameEndpoint() {
        let endpoint = Endpoint.creditPerson(.name("John Newton"))
        #expect(endpoint.action == "musician")
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
                ],
                "ccli": "1234567",
                "bowi": "BW-0001",
                "subtitle": "A Hymn Tune",
                "disambiguation": "(traditional)",
                "tuneName": "NEW BRITAIN",
                "tuneId": 42,
                "firstPublishedYear": 1779,
                "copyrightYears": "",
                "copyrightHolder": ""
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
        // #1752 Slice C — the nine #1741 P4b identity keys, present (the
        // standalone `getWork()` shape).
        #expect(work.ccli == "1234567")
        #expect(work.bowi == "BW-0001")
        #expect(work.subtitle == "A Hymn Tune")
        #expect(work.disambiguation == "(traditional)")
        #expect(work.tuneName == "NEW BRITAIN")
        #expect(work.tuneId == 42)
        #expect(work.firstPublishedYear == 1779)
        #expect(work.copyrightYears == "")
        #expect(work.copyrightHolder == "")
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

    @Test("Decodes a work envelope whose nine #1741 P4b keys are entirely absent — the SAME embedded-shape tolerance, in BOTH directions (#1752 Slice C)")
    func decodesWorkMissingP4bKeys() throws {
        // The embedded `song_detail.works[]` shape omits `children` (proven
        // above) AND all nine P4b keys — `_worksMap()` never sets any of
        // them. This is the other absent-tolerance direction the build spec
        // asks for: proving decode succeeds with the keys missing, not just
        // that specific values decode correctly when present.
        let json = Data("""
        {
            "work": {
                "id": 7, "parentId": null, "title": "Amazing Grace", "slug": "amazing-grace", "iswc": null,
                "members": [], "links": []
            }
        }
        """.utf8)

        let work = try APIClient.decodeWork(from: json)
        #expect(work.ccli == nil)
        #expect(work.bowi == nil)
        #expect(work.subtitle == nil)
        #expect(work.disambiguation == nil)
        #expect(work.tuneName == nil)
        #expect(work.tuneId == nil)
        #expect(work.firstPublishedYear == nil)
        #expect(work.copyrightYears == nil)
        #expect(work.copyrightHolder == nil)
        #expect(work.copyrightDisplay == "")
    }

    // MARK: - Musician / credit person — modelled from `SongData::getMusician()`'s own array literal
    //
    // #1752 Slice D UPDATE — the envelope key is now `musician` (was
    // `person`), matching `decodeCreditPerson(from:)`'s switch
    // (`WorkAndCreditPersonDecoding.swift`) to the canonical `?action=musician`
    // action `Endpoint.creditPerson(_:)` now builds.

    @Test("Decodes a full musician envelope with discography, links, and identifiers")
    func decodesCreditPersonWithFullShape() throws {
        let json = Data("""
        {
            "musician": {
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
                "totalSongs": 1,
                "identifiers": [
                    { "type": "ipi", "value": "00123456789" }
                ]
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
        // #1752 Slice D
        #expect(person.identifiers?.count == 1)
        #expect(person.identifiers?.first?.type == "ipi")
        #expect(person.identifiers?.first?.value == "00123456789")
    }

    @Test("Decodes a name-only musician envelope — no registry row, id/slug are null (#1444)")
    func decodesCreditPersonWithNoRegistryRow() throws {
        // `SongData::getMusician()`'s own "no row, but has credited songs"
        // fallback — the ONLY case reachable when a native client taps a
        // plain credit-name string with no `tblMusicians` row yet.
        let json = Data("""
        {
            "musician": {
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

    @Test("Decodes a musician envelope whose 'identifiers' key is entirely absent — pre-#1752 server tolerance")
    func decodesCreditPersonMissingIdentifiersKey() throws {
        // The server-side default is always-present `'identifiers' => []`
        // (`SongData::getMusician()`, #1752 §4.1) — but an already-cached
        // native payload fetched before that change shipped, or a
        // pre-#1752 docroot in a staggered rollout, sends no `identifiers`
        // key at all. Must decode to `nil`, never throw.
        let json = Data("""
        {
            "musician": {
                "id": 3, "slug": "fanny-crosby", "name": "Fanny Crosby",
                "notes": null, "birthPlace": null, "birthDate": null, "deathPlace": null, "deathDate": null,
                "isSpecialCase": false, "isGroup": false,
                "discography": [], "links": [], "totalSongs": 0
            }
        }
        """.utf8)

        let person = try APIClient.decodeCreditPerson(from: json)
        #expect(person.identifiers == nil)
    }
}
