<?php

declare(strict_types=1);

/**
 * iHymns — Places registry helper
 *
 * Single source of truth for upserting + reading rows from
 * `tblPlaces`. Every admin form that wires the live-location
 * autocomplete (Credit People birth / death place today,
 * follow-up tables tomorrow) routes its picked-place payload
 * through `placesUpsertFromPayload()` so the row is created
 * once and re-used on subsequent picks.
 *
 * Schema-tolerant — every function below returns NULL / empty
 * gracefully on installs that haven't run migrate-places.php
 * yet, so a partly-migrated database doesn't 500.
 */

if (!function_exists('getDbMysqli')) {
    require_once __DIR__ . '/db_mysql.php';
}

/**
 * Cached existence probe for tblPlaces. Static per-request cache
 * so the INFORMATION_SCHEMA round-trip happens at most once.
 */
function placesTableExists(mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPlaces' LIMIT 1"
    );
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $cached;
}

/**
 * Cached probe for the place-id FK columns on tblCreditPeople.
 * Used by credit-people.php's add / update path to choose between
 * the legacy INSERT shape and the place-id-aware one without
 * re-querying INFORMATION_SCHEMA on every save.
 */
function creditPeoplePlaceIdColumnsExist(mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblCreditPeople'
            AND COLUMN_NAME IN ('BirthPlaceId', 'DeathPlaceId')"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return ($cached = $row && (int)$row[0] === 2);
}

/**
 * Collapse OSM's tier of city-ish keys (`city`, `town`, `village`,
 * `hamlet`, `municipality`, `locality`) into the single `City`
 * column. Photon and Nominatim both emit one of these depending on
 * the place's actual OSM tags; storing them under one column keeps
 * the read side simple.
 */
function _placesCollapseCity(array $address): ?string
{
    foreach (['city', 'town', 'village', 'hamlet', 'municipality', 'locality'] as $k) {
        if (!empty($address[$k])) return (string)$address[$k];
    }
    return null;
}

/**
 * Normalise a geocoder payload (Photon or Nominatim) into the
 * column-shape tblPlaces stores. Caller is the API proxy
 * (manage/places-api.php) — clients post the chosen candidate
 * back to us, we re-normalise here rather than trusting the
 * client-supplied parts so a stale / tampered POST can't poison
 * the registry.
 *
 * Returns NULL when the payload is too sparse to be useful (no
 * display name).
 *
 * @param array $candidate raw normalised candidate (see API doc)
 * @return array|null      shape: [Provider, OsmType, OsmId, …]
 */
function placesNormaliseCandidate(array $candidate): ?array
{
    $displayName = trim((string)($candidate['display_name'] ?? ''));
    if ($displayName === '') return null;

    /* OSM type is 'N' / 'W' / 'R' on Photon, 'node' / 'way' /
       'relation' on Nominatim. Normalise to the single-letter form
       so the natural-key UNIQUE index does its job regardless of
       which upstream resolved the search. */
    $osmTypeRaw = strtolower((string)($candidate['osm_type'] ?? ''));
    $osmType    = null;
    if ($osmTypeRaw !== '') {
        $first = $osmTypeRaw[0];
        if (in_array($first, ['n', 'w', 'r'], true)) {
            $osmType = strtoupper($first);
        }
    }

    $osmId = isset($candidate['osm_id']) && $candidate['osm_id'] !== ''
        ? (int)$candidate['osm_id']
        : null;

    /* The address sub-object is where the parsed parts live. Photon
       puts them at the top level; Nominatim under `address`. The
       proxy already merged them — we accept both shapes here so the
       helper stays callable from tests / fixtures without going
       through the proxy. */
    $address = is_array($candidate['address'] ?? null)
        ? $candidate['address']
        : $candidate;

    $countryCode = strtolower((string)($address['country_code'] ?? ''));
    if (!preg_match('/^[a-z]{2}$/', $countryCode)) {
        $countryCode = null;
    }

    $lat = isset($candidate['lat']) && $candidate['lat'] !== ''
        ? (float)$candidate['lat']
        : null;
    $lon = isset($candidate['lon']) && $candidate['lon'] !== ''
        ? (float)$candidate['lon']
        : null;

    /* Clamp into the DECIMAL(10,7) range so MySQL doesn't reject
       an out-of-bounds payload from a buggy upstream. */
    if ($lat !== null && ($lat < -90  || $lat > 90))  $lat = null;
    if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

    return [
        'Provider'    => 'osm',
        'OsmType'     => $osmType,
        'OsmId'       => $osmId,
        'DisplayName' => mb_substr($displayName, 0, 500),
        'Name'        => isset($candidate['name']) && $candidate['name'] !== ''
            ? mb_substr((string)$candidate['name'], 0, 255)
            : null,
        'Suburb'      => isset($address['suburb']) && $address['suburb'] !== ''
            ? mb_substr((string)$address['suburb'], 0, 255)
            : null,
        'City'        => _placesCollapseCity($address),
        'County'      => isset($address['county']) && $address['county'] !== ''
            ? mb_substr((string)$address['county'], 0, 255)
            : null,
        'Region'      => isset($address['state']) && $address['state'] !== ''
            ? mb_substr((string)$address['state'], 0, 255)
            : (isset($address['region']) && $address['region'] !== ''
                ? mb_substr((string)$address['region'], 0, 255)
                : null),
        'Country'     => isset($address['country']) && $address['country'] !== ''
            ? mb_substr((string)$address['country'], 0, 255)
            : null,
        'CountryCode' => $countryCode,
        'Latitude'    => $lat,
        'Longitude'   => $lon,
        'PlaceType'   => isset($candidate['type']) && $candidate['type'] !== ''
            ? mb_substr((string)$candidate['type'], 0, 50)
            : null,
    ];
}

/**
 * Upsert a normalised payload into tblPlaces, returning the Id and
 * a freshly-loaded row for the caller's response. Re-uses an
 * existing row when the (Provider, OsmType, OsmId) triple matches —
 * that's what makes two curators picking the same OSM place land
 * on the same registry row.
 *
 * Returns NULL when tblPlaces hasn't been migrated yet, when the
 * payload doesn't normalise (missing display name), or on a DB
 * error. Callers MUST handle the NULL case gracefully (the
 * autocomplete still works — the caller just doesn't get a place
 * Id to persist alongside the display string).
 */
function placesUpsertFromPayload(mysqli $db, array $candidate): ?array
{
    if (!placesTableExists($db)) return null;
    $row = placesNormaliseCandidate($candidate);
    if ($row === null) return null;

    /* Localise into named variables — bind_param wants references,
       and PHP array-index references can be quirky across versions.
       Cheap copy; the row payload is small. */
    $provider    = $row['Provider'];
    $osmType     = $row['OsmType'];
    $osmId       = $row['OsmId'];
    $displayName = $row['DisplayName'];
    $shortName   = $row['Name'];
    $suburb      = $row['Suburb'];
    $city        = $row['City'];
    $county      = $row['County'];
    $region      = $row['Region'];
    $country     = $row['Country'];
    $countryCode = $row['CountryCode'];
    $latitude    = $row['Latitude'];
    $longitude   = $row['Longitude'];
    $placeType   = $row['PlaceType'];

    /* Look up by natural key first — only meaningful when OsmId is
       set. NULL OsmId rows (manual entries) skip this and always
       INSERT, because the UNIQUE index treats NULLs as distinct. */
    if ($osmId !== null && $osmType !== null) {
        $stmt = $db->prepare(
            'SELECT Id FROM tblPlaces
              WHERE Provider = ? AND OsmType = ? AND OsmId = ?
              LIMIT 1'
        );
        $stmt->bind_param('ssi', $provider, $osmType, $osmId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing && !empty($existing['Id'])) {
            /* Refresh display + parsed parts so the canonical row
               picks up any geocoder corrections since first insert.
               Cheap UPDATE, runs only on the picked-row's narrow
               primary key. */
            $existingId = (int)$existing['Id'];
            $upd = $db->prepare(
                'UPDATE tblPlaces
                    SET DisplayName = ?, Name = ?, Suburb = ?, City = ?,
                        County = ?, Region = ?, Country = ?, CountryCode = ?,
                        Latitude = ?, Longitude = ?, PlaceType = ?
                  WHERE Id = ?'
            );
            $upd->bind_param(
                'ssssssssddsi',
                $displayName, $shortName, $suburb, $city,
                $county, $region, $country, $countryCode,
                $latitude, $longitude, $placeType,
                $existingId
            );
            $upd->execute();
            $upd->close();
            return placesLoadById($db, $existingId);
        }
    }

    /* INSERT path — new place. */
    $stmt = $db->prepare(
        'INSERT INTO tblPlaces
            (Provider, OsmType, OsmId, DisplayName, Name, Suburb, City,
             County, Region, Country, CountryCode, Latitude, Longitude, PlaceType)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'ssisssssssddds',
        $provider, $osmType, $osmId, $displayName, $shortName,
        $suburb, $city, $county, $region, $country, $countryCode,
        $latitude, $longitude, $placeType
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $newId = (int)$db->insert_id;
    $stmt->close();
    return placesLoadById($db, $newId);
}

/**
 * Fetch one place row, shaped for JSON responses + the form
 * pre-fill payload. Returns NULL when the row doesn't exist
 * (or tblPlaces isn't migrated yet).
 */
function placesLoadById(mysqli $db, int $id): ?array
{
    if ($id <= 0 || !placesTableExists($db)) return null;
    $stmt = $db->prepare(
        'SELECT Id, Provider, OsmType, OsmId, DisplayName, Name,
                Suburb, City, County, Region, Country, CountryCode,
                Latitude, Longitude, PlaceType
           FROM tblPlaces
          WHERE Id = ?
          LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return null;
    return [
        'id'           => (int)$row['Id'],
        'provider'     => (string)$row['Provider'],
        'osm_type'     => $row['OsmType'],
        'osm_id'       => $row['OsmId'] !== null ? (int)$row['OsmId'] : null,
        'display_name' => (string)$row['DisplayName'],
        'name'         => $row['Name'],
        'suburb'       => $row['Suburb'],
        'city'         => $row['City'],
        'county'       => $row['County'],
        'region'       => $row['Region'],
        'country'      => $row['Country'],
        'country_code' => $row['CountryCode'],
        'lat'          => $row['Latitude']  !== null ? (float)$row['Latitude']  : null,
        'lon'          => $row['Longitude'] !== null ? (float)$row['Longitude'] : null,
        'type'         => $row['PlaceType'],
    ];
}
