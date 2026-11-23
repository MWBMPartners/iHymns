/**
 * profile-web-codec.js - turn the web's stored TEXT into the registry's real values and back
 * =========================================================================================
 * Account/profile rewrite, PR 2b step 2b-4 (epic #2180). Pure: no storage, no network, no DOM.
 *
 * ELI5
 * ----
 * The browser's storage only holds words ("true", "18", "{...}"). The account's list of personal
 * settings (the profile registry) holds real values: a true/false, a number, an object. Something
 * has to translate between the two, and BOTH the browser and the server must translate the same
 * way, or a setting changes meaning on its way across. This file is the browser's translator; the
 * server's twin is `profileWebDecode()` / `profileWebEncode()` in includes/profile_sync.php.
 * tests/test-profile-web-codec-lockstep.js makes the two answer the same on the same samples.
 *
 * Detailed
 * --------
 *  - `decodeWebValue(entry, text)` returns the real value, or `undefined` when the text is not a valid
 *    value for the entry (the server returns null; JavaScript uses `undefined` so a real `null`, `false`
 *    or `0` can never be mistaken for "invalid"). A true/false value is read from "true" or "1" (false from
 *    "false" or "0"). The result is checked against the entry's own rules by `validateSpec()`.
 *  - `encodeWebValue(entry, value)` returns the text to store: "true" / "false", digits for a number,
 *    compact JSON for an object or list (an empty object as "{}").
 *  - `validateSpec(spec, value)` is the registry's validator (profileValidateSpec in PHP), working on the
 *    `entries` of the downloaded `profile_schema`, never on a typed list.
 *
 * @module modules/profile-web-codec
 * @see appWeb/public_html/includes/profile_sync.php  profileWebDecode(), profileWebEncode()
 * @see appWeb/public_html/includes/profile_registry.php  profileValidateSpec()
 */

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** Does `value` satisfy the registry spec? Mirrors profileValidateSpec() in PHP. */
export function validateSpec(spec, value) {
    switch (spec && spec.type) {
        case 'bool':
            return typeof value === 'boolean';
        case 'int': {
            if (!Number.isInteger(value)) return false;
            const step = spec.step ?? 1;
            const from = spec.from ?? spec.min ?? 0;
            return value >= spec.min && value <= spec.max && step >= 1 && (value - from) % step === 0;
        }
        case 'float':
            return typeof value === 'number' && Number.isFinite(value) && value >= spec.min && value <= spec.max;
        case 'enum':
            return typeof value === 'string' && Array.isArray(spec.allowed) && spec.allowed.includes(value);
        case 'string': {
            if (typeof value !== 'string' || value === '') return false;
            if (spec.maxLength != null && Array.from(value).length > spec.maxLength) return false;
            if (spec.pattern != null && !new RegExp('^(?:' + spec.pattern.replace(/^\^/, '').replace(/\$$/, '') + ')$').test(value)) return false;
            return true;
        }
        case 'json': {
            if (!isPlainObject(value)) return false;
            const shape = spec.shape || {};
            const keys = Object.keys(shape);
            if (keys.length === 0) return true;
            return Object.keys(value).every((k) => Object.prototype.hasOwnProperty.call(shape, k) && validateSpec(shape[k], value[k]));
        }
        case 'list':
            if (!Array.isArray(value)) return false;
            if (spec.maxItems != null && value.length > spec.maxItems) return false;
            return value.every((v) => validateSpec(spec.item, v));
        case 'map': {
            if (!isPlainObject(value)) return false;
            const keys = Object.keys(value);
            if (spec.maxItems != null && keys.length > spec.maxItems) return false;
            const re = new RegExp('^(?:' + String(spec.itemKeyPattern || '$^').replace(/^\^/, '').replace(/\$$/, '') + ')$');
            return keys.every((k) => re.test(k) && validateSpec(spec.item, value[k]));
        }
        default:
            return false;
    }
}

/**
 * Text from storage -> the registry's real value, or `undefined` when it is not valid.
 * @param {object} entry a registry entry (from `profile_schema`)
 * @param {string} text  what the browser's storage holds
 */
export function decodeWebValue(entry, text) {
    if (typeof text !== 'string') return undefined;
    let v;
    switch (entry && entry.type) {
        case 'bool':
            v = (text === 'true' || text === '1') ? true : ((text === 'false' || text === '0') ? false : undefined);
            break;
        case 'int':
            v = /^-?[0-9]{1,9}$/.test(text) ? parseInt(text, 10) : undefined;
            break;
        case 'float':
            v = /^-?[0-9]{1,9}(\.[0-9]{1,9})?$/.test(text) ? parseFloat(text) : undefined;
            break;
        case 'enum':
        case 'string':
            v = text !== '' ? text : undefined;
            break;
        case 'json':
        case 'list': {
            try {
                const d = JSON.parse(text);
                v = (entry.type === 'json' ? isPlainObject(d) : Array.isArray(d)) ? d : undefined;
            } catch (_e) { v = undefined; }
            break;
        }
        default:
            v = undefined;
    }
    return (v !== undefined && validateSpec(entry, v)) ? v : undefined;
}

/**
 * The registry's real value -> the text the browser stores.
 * @param {object} entry a registry entry
 * @param {unknown} value a value that is valid for the entry
 */
export function encodeWebValue(entry, value) {
    switch (entry && entry.type) {
        case 'bool':
            return value === true ? 'true' : 'false';
        case 'int':
            return String(Math.trunc(Number(value)));
        case 'float':
            return String(Number(value));
        case 'json':
        case 'list':
        case 'map':
            return JSON.stringify(value);
        default:
            return String(value);
    }
}
