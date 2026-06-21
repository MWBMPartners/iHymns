/**
 * iHymns — Song existence check (#1329)
 *
 * Batch-checks which SongIds still exist in the catalogue, so localStorage-
 * backed lists (Recently Viewed, and optionally Favourites / Setlists) can
 * prune songs that have since been DELETED — a stale cached entry would
 * otherwise render an unresolvable id (the deleted "Here to Stay" the owner
 * spotted in Recently Viewed).
 *
 * Returns a Set of the ids that STILL exist, or `null` if the check couldn't
 * run (network/parse error) so callers leave their cache UNTOUCHED rather than
 * wrongly wiping it. Every id is checked (batched ≤150 to stay under the
 * server's 200-id cap), so an id absent from the returned Set is genuinely
 * gone — never just un-checked.
 */

const _CHUNK = 150;

/**
 * @param {object} app  App instance (for app.config.apiUrl).
 * @param {string[]} ids  SongIds to check.
 * @returns {Promise<Set<string>|null>}  Set of existing ids, or null on failure.
 */
export async function songsExist(app, ids) {
    const unique = [...new Set((ids || []).filter(Boolean))];
    if (unique.length === 0) return new Set();

    const base = app?.config?.apiUrl;
    if (!base) return null;

    const found = new Set();
    try {
        for (let i = 0; i < unique.length; i += _CHUNK) {
            const batch = unique.slice(i, i + _CHUNK);
            const url = `${base}?action=songs_exist&ids=${encodeURIComponent(batch.join(','))}`;
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return null;
            const data = await res.json();
            /* exists === null is the server's "couldn't check" sentinel; an
               array (incl. empty) is authoritative. Anything else → bail safe. */
            if (!data || !Array.isArray(data.exists)) return null;
            data.exists.forEach((id) => found.add(id));
        }
        return found;
    } catch {
        return null;
    }
}
