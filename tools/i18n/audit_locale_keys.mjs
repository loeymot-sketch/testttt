/**
 * i18n locale key audit — Vue (JSON) vs Laravel (PHP lang files), two separate passes.
 */
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, extname, basename, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const AUDIT_DATE = '2026-04-20';
const VUE_LOCALES_DIR = 'resources/js/languages';
const VUE_COMPONENTS_DIR = 'resources/js/components';
const VUE_JS_ROOT = 'resources/js';
const BLADE_DIR = 'resources/views';
const APP_DIR = 'app';
const LARAVEL_LANG_DIR = 'lang';
const REPORTS_DIR = 'reports/i18n';
const LOCALES = ['fr', 'en', 'ar', 'de', 'bn'];

const SKIP_DIR_NAMES = new Set([
    'node_modules',
    'vendor',
    'dist',
    'public',
    'storage',
    'bootstrap',
    'cache',
    'languages',
]);

/** @param {unknown} obj */
export function flattenJson(obj, prefix = '') {
    const out = {};
    if (obj === null || typeof obj !== 'object') {
        if (prefix) out[prefix] = obj;
        return out;
    }
    if (Array.isArray(obj)) {
        obj.forEach((item, i) => {
            const key = `${prefix}.${i}`;
            if (item !== null && typeof item === 'object' && !Array.isArray(item)) {
                Object.assign(out, flattenJson(item, key));
            } else {
                out[key] = item;
            }
        });
        return out;
    }
    for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
            Object.assign(out, flattenJson(v, key));
        } else {
            out[key] = v;
        }
    }
    return out;
}

/**
 * Best-effort PHP locale array parser. On failure returns {} and caller may log.
 * @param {string} content
 * @param {string} topLevelPrefix basename without .php (e.g. 'auth'); '' = keys unprefixed (tests)
 * @returns {Record<string, string>}
 */
export function parsePhpLocaleFile(content, topLevelPrefix = '') {
    try {
        let s = stripPhpComments(String(content));
        s = s.replace(/^\s*<\?php\s*/i, '').replace(/\?>\s*$/m, '');
        const retIdx = s.search(/\breturn\s*\[/);
        if (retIdx === -1) return {};
        const openBracket = s.indexOf('[', retIdx);
        if (openBracket === -1) return {};
        const balanced = extractBalancedArray(s, openBracket);
        if (!balanced) return {};
        const inner = balanced.inner;
        const flat = {};
        parsePhpArrayBody(inner, topLevelPrefix, flat);
        return flat;
    } catch {
        return {};
    }
}

function stripPhpComments(str) {
    let out = '';
    let i = 0;
    const len = str.length;
    while (i < len) {
        if (str[i] === '/' && str[i + 1] === '*') {
            i += 2;
            while (i < len - 1 && !(str[i] === '*' && str[i + 1] === '/')) i++;
            i = Math.min(len, i + 2);
            continue;
        }
        if (str[i] === '/' && str[i + 1] === '/') {
            while (i < len && str[i] !== '\n' && str[i] !== '\r') i++;
            continue;
        }
        out += str[i];
        i++;
    }
    return out;
}

/**
 * @param {string} s
 * @param {number} openIdx index of '['
 */
function extractBalancedArray(s, openIdx) {
    let depth = 0;
    let i = openIdx;
    const startContent = openIdx + 1;
    while (i < s.length) {
        const ch = s[i];
        if (ch === "'" || ch === '"') {
            i = skipPhpString(s, i);
            continue;
        }
        if (ch === '[') depth++;
        if (ch === ']') {
            depth--;
            if (depth === 0) {
                return { inner: s.slice(startContent, i), end: i + 1 };
            }
        }
        i++;
    }
    return null;
}

function skipPhpString(s, start) {
    const q = s[start];
    let i = start + 1;
    while (i < s.length) {
        if (s[i] === '\\') {
            i += 2;
            continue;
        }
        if (s[i] === q) return i + 1;
        i++;
    }
    return s.length;
}

/**
 * @param {string} body inside [...] without outer brackets
 * @param {string} prefix dot path or ''
 * @param {Record<string, string>} out
 */
function parsePhpArrayBody(body, prefix, out) {
    let i = 0;
    const len = body.length;
    while (i < len) {
        while (i < len && /\s|,/.test(body[i])) i++;
        if (i >= len) break;
        const keyStart = i;
        const qc = body[i];
        if (qc !== "'" && qc !== '"') {
            i++;
            continue;
        }
        const keyEnd = skipPhpString(body, keyStart);
        const keyRaw = body.slice(keyStart + 1, keyEnd - 1);
        const unescapedKey = unescapePhpString(keyRaw, qc);
        i = keyEnd;
        while (i < len && /\s/.test(body[i])) i++;
        if (body[i] !== '=' || body[i + 1] !== '>') {
            i++;
            continue;
        }
        i += 2;
        while (i < len && /\s/.test(body[i])) i++;
        if (i >= len) break;
        const fullKey = prefix ? `${prefix}.${unescapedKey}` : unescapedKey;

        if (body[i] === '[') {
            const sub = extractBalancedArray(body, i);
            if (!sub) break;
            parsePhpArrayBody(sub.inner, fullKey, out);
            i = sub.end;
            continue;
        }
        if (body[i] === "'" || body[i] === '"') {
            const vs = body[i];
            const ve = skipPhpString(body, i);
            const valRaw = body.slice(i + 1, ve - 1);
            out[fullKey] = unescapePhpString(valRaw, vs);
            i = ve;
            continue;
        }
        i++;
    }
}

function unescapePhpString(raw, quote) {
    return raw.replace(/\\(.)/g, (_, c) => {
        if (c === '\\') return '\\';
        if (c === quote) return quote;
        if (c === 'n') return '\n';
        if (c === 'r') return '\r';
        if (c === 't') return '\t';
        return c;
    });
}

/**
 * @param {string} source
 * @returns {{ staticKeys: string[], dynamicCount: number }}
 */
export function extractI18nKeysFromVue(source) {
    const staticKeys = [];
    let dynamicCount = 0;

    const qStr = `['"]`;
    const reString = new RegExp(`\\$t(?:c)?\\(\\s*${qStr}([^'"]+)${qStr}`, 'g');
    let m;
    while ((m = reString.exec(source)) !== null) {
        staticKeys.push(m[1]);
    }

    const reI18n = new RegExp(`\\$i18n\\.t\\(\\s*${qStr}([^'"]+)${qStr}`, 'g');
    while ((m = reI18n.exec(source)) !== null) {
        staticKeys.push(m[1]);
    }

    const reI18nShort = new RegExp(`i18n\\.t\\(\\s*${qStr}([^'"]+)${qStr}`, 'g');
    while ((m = reI18nShort.exec(source)) !== null) {
        staticKeys.push(m[1]);
    }

    const reUtilT = new RegExp(`(?<!\\$)\\bt\\(\\s*${qStr}([^'"]+)${qStr}\\)`, 'g');
    while ((m = reUtilT.exec(source)) !== null) {
        staticKeys.push(m[1]);
    }

    const reTpl = /\$t(?:c)?\(\s*`([^`]*?)`\s*\)/g;
    while ((m = reTpl.exec(source)) !== null) {
        if (/\$\{/.test(m[1])) {
            dynamicCount += 1;
        } else {
            staticKeys.push(m[1]);
        }
    }

    const reI18nTpl = /\$i18n\.t\(\s*`([^`]*?)`\s*\)/g;
    while ((m = reI18nTpl.exec(source)) !== null) {
        if (/\$\{/.test(m[1])) {
            dynamicCount += 1;
        } else {
            staticKeys.push(m[1]);
        }
    }

    return { staticKeys, dynamicCount };
}

/**
 * @param {string} source
 * @returns {{ staticKeys: string[], dynamicCount: number }}
 */
export function extractI18nKeysFromBlade(source) {
    const staticKeys = [];
    let dynamicCount = 0;

    const q = `['"\x60]`;
    const reStatic = new RegExp(
        `__\\(\\s*${q}([^'"\x60]+)${q}|@lang\\(\\s*${q}([^'"\x60]+)${q}|Lang::get\\(\\s*${q}([^'"\x60]+)${q}|trans\\(\\s*${q}([^'"\x60]+)${q}`,
        'g',
    );
    let m;
    while ((m = reStatic.exec(source)) !== null) {
        const key = m[1] || m[2] || m[3] || m[4];
        if (key) staticKeys.push(key);
    }

    const reUndTpl = /__\(\s*`([^`]*?)`\s*\)/g;
    while ((m = reUndTpl.exec(source)) !== null) {
        if (/\$\{/.test(m[1])) {
            dynamicCount += 1;
        } else {
            staticKeys.push(m[1]);
        }
    }

    const reLangTpl = /@lang\(\s*`([^`]*?)`\s*\)/g;
    while ((m = reLangTpl.exec(source)) !== null) {
        if (/\$\{/.test(m[1])) {
            dynamicCount += 1;
        } else {
            staticKeys.push(m[1]);
        }
    }

    const reLangGetTpl = /Lang::get\(\s*`([^`]*?)`\s*\)/g;
    while ((m = reLangGetTpl.exec(source)) !== null) {
        if (/\$\{/.test(m[1])) {
            dynamicCount += 1;
        } else {
            staticKeys.push(m[1]);
        }
    }

    const reTransTpl = /trans\(\s*`([^`]*?)`\s*\)/g;
    while ((m = reTransTpl.exec(source)) !== null) {
        if (/\$\{/.test(m[1])) {
            dynamicCount += 1;
        } else {
            staticKeys.push(m[1]);
        }
    }

    return { staticKeys, dynamicCount };
}

/**
 * @param {string} source
 * @returns {{ staticKeys: string[], dynamicCount: number }}
 */
export function extractI18nKeysFromJs(source) {
    return extractI18nKeysFromVue(source);
}

/**
 * @param {string[]} usedKeys
 * @param {Record<string, unknown>} presentMap flattened locale map
 */
export function diffMissing(usedKeys, presentMap) {
    const present = new Set(Object.keys(presentMap));
    return usedKeys.filter((k) => !present.has(k));
}

/**
 * Resolve Laravel translation key against one locale directory (e.g. lang/fr).
 * @param {string} key e.g. auth.failed
 * @param {string} langDirAbs absolute path to lang/fr
 * @returns {boolean}
 */
export function resolveBladeKeyToPhp(key, langDirAbs) {
    const parts = key.split('.');
    if (parts.length < 1) return false;
    const fileStem = parts[0];
    const rest = parts.slice(1).join('.');
    const phpPath = join(langDirAbs, `${fileStem}.php`);
    let content;
    try {
        content = readFileSync(phpPath, 'utf8');
    } catch {
        return false;
    }
    const flat = parsePhpLocaleFile(content, fileStem);
    const fullKey = rest ? `${fileStem}.${rest}` : fileStem;
    if (Object.prototype.hasOwnProperty.call(flat, fullKey)) return true;
    if (!rest && Object.prototype.hasOwnProperty.call(flat, fileStem)) return true;
    return false;
}

function shouldSkipPath(relPath) {
    const lower = relPath.toLowerCase();
    if (lower.endsWith('.test.js')) return true;
    if (lower.endsWith('.spec.js')) return true;
    return false;
}

function walkDir(absRoot, relRoot, files, extFilter) {
    let dirents;
    try {
        dirents = readdirSync(absRoot, { withFileTypes: true });
    } catch {
        return;
    }
    for (const d of dirents) {
        const name = d.name;
        const abs = join(absRoot, name);
        const rel = join(relRoot, name);
        if (d.isDirectory()) {
            if (SKIP_DIR_NAMES.has(name)) continue;
            walkDir(abs, rel, files, extFilter);
        } else if (d.isFile()) {
            if (extFilter.length && !extFilter.includes(extname(name))) continue;
            if (shouldSkipPath(rel)) continue;
            files.push(rel);
        }
    }
}

function csvEscape(s) {
    const str = String(s);
    if (/[",\n\r]/.test(str)) return `"${str.replace(/"/g, '""')}"`;
    return str;
}

function writeCsv(path, rows, header) {
    const lines = [header.join(',')];
    for (const row of rows) {
        lines.push(row.map(csvEscape).join(','));
    }
    writeFileSync(path, lines.join('\n') + '\n', 'utf8');
}

function resolveRepoRoot() {
    return resolve(fileURLToPath(new URL('../..', import.meta.url)));
}

function loadVueLocaleMaps(root) {
    /** @type {Record<string, Record<string, unknown>>} */
    const localeMaps = {};
    for (const loc of LOCALES) {
        const p = join(root, VUE_LOCALES_DIR, `${loc}.json`);
        const raw = JSON.parse(readFileSync(p, 'utf8'));
        localeMaps[loc] = flattenJson(raw);
    }
    return localeMaps;
}

/**
 * Merge all *.php in lang/{locale}/ into one flat map (file stem = top-level namespace).
 */
function loadLaravelLocaleMaps(root, stats) {
    /** @type {Record<string, Record<string, string>>} */
    const byLocale = {};
    for (const loc of LOCALES) {
        byLocale[loc] = {};
        const dir = join(root, LARAVEL_LANG_DIR, loc);
        let files;
        try {
            files = readdirSync(dir, { withFileTypes: true });
        } catch {
            continue;
        }
        for (const d of files) {
            if (!d.isFile() || extname(d.name) !== '.php') continue;
            const stem = basename(d.name, '.php');
            const abs = join(dir, d.name);
            let content;
            try {
                content = readFileSync(abs, 'utf8');
            } catch {
                stats.filesFailed += 1;
                console.warn(`[i18n] skip unreadable: ${abs}`);
                continue;
            }
            const parsed = parsePhpLocaleFile(content, stem);
            stats.filesParsed += 1;
            Object.assign(byLocale[loc], parsed);
        }
    }
    return byLocale;
}

export function main() {
    const root = resolveRepoRoot();

    const vueFiles = [];
    walkDir(join(root, VUE_COMPONENTS_DIR), VUE_COMPONENTS_DIR, vueFiles, ['.vue']);

    const jsFiles = [];
    walkDir(join(root, VUE_JS_ROOT), VUE_JS_ROOT, jsFiles, ['.js']);
    const jsFiltered = jsFiles.filter((p) => {
        const n = p.split(/[/\\]/).join('/');
        return !n.startsWith('resources/js/languages/') && n !== 'resources/js/languages';
    });

    const keyFirstFileVue = new Map();
    const allStaticVue = [];
    let dynamicVue = 0;

    function ingestVue(fileRel, extractFn) {
        const abs = join(root, fileRel);
        let content;
        try {
            content = readFileSync(abs, 'utf8');
        } catch {
            return;
        }
        const { staticKeys, dynamicCount } = extractFn(content);
        dynamicVue += dynamicCount;
        for (const k of staticKeys) {
            if (!keyFirstFileVue.has(k)) keyFirstFileVue.set(k, fileRel);
            allStaticVue.push(k);
        }
    }

    for (const f of vueFiles) ingestVue(f, extractI18nKeysFromVue);
    for (const f of jsFiltered) ingestVue(f, extractI18nKeysFromJs);

    const usedUniqueVue = [...new Set(allStaticVue)];

    const bladeFiles = [];
    walkDir(join(root, BLADE_DIR), BLADE_DIR, bladeFiles, ['.php']);
    const appPhpFiles = [];
    walkDir(join(root, APP_DIR), APP_DIR, appPhpFiles, ['.php']);

    const keyFirstFileLaravel = new Map();
    const allStaticLaravel = [];
    let dynamicLaravel = 0;

    function ingestLaravel(fileRel, extractFn) {
        const abs = join(root, fileRel);
        let content;
        try {
            content = readFileSync(abs, 'utf8');
        } catch {
            return;
        }
        const { staticKeys, dynamicCount } = extractFn(content);
        dynamicLaravel += dynamicCount;
        for (const k of staticKeys) {
            if (!keyFirstFileLaravel.has(k)) keyFirstFileLaravel.set(k, fileRel);
            allStaticLaravel.push(k);
        }
    }

    for (const f of bladeFiles) {
        if (!f.endsWith('.blade.php')) continue;
        ingestLaravel(f, extractI18nKeysFromBlade);
    }
    for (const f of appPhpFiles) {
        ingestLaravel(f, extractI18nKeysFromBlade);
    }

    const usedUniqueLaravel = [...new Set(allStaticLaravel)];

    const localeMapsVue = loadVueLocaleMaps(root);
    const laravelStats = { filesParsed: 0, filesFailed: 0 };
    const localeMapsLaravel = loadLaravelLocaleMaps(root, laravelStats);

    const allJsonKeys = new Set();
    for (const loc of LOCALES) {
        for (const k of Object.keys(localeMapsVue[loc])) allJsonKeys.add(k);
    }

    const usedSetVue = new Set(usedUniqueVue);
    const deadKeysVue = [...allJsonKeys].filter((k) => !usedSetVue.has(k)).sort();

    const allPhpKeys = new Set();
    for (const loc of LOCALES) {
        for (const k of Object.keys(localeMapsLaravel[loc])) allPhpKeys.add(k);
    }
    const usedSetLaravel = new Set(usedUniqueLaravel);
    const deadKeysLaravel = [...allPhpKeys].filter((k) => !usedSetLaravel.has(k)).sort();

    const frMap = localeMapsVue.fr;
    const enMap = localeMapsVue.en;
    const identicalVue = [];
    for (const key of Object.keys(frMap)) {
        if (!Object.prototype.hasOwnProperty.call(enMap, key)) continue;
        const fv = frMap[key];
        const ev = enMap[key];
        if (typeof fv !== 'string' || typeof ev !== 'string') continue;
        if (fv === ev && fv.length > 5) identicalVue.push({ key, fr: fv, en: ev });
    }
    identicalVue.sort((a, b) => a.key.localeCompare(b.key));

    const frMapL = localeMapsLaravel.fr;
    const enMapL = localeMapsLaravel.en;
    const identicalLaravel = [];
    for (const key of Object.keys(frMapL)) {
        if (!Object.prototype.hasOwnProperty.call(enMapL, key)) continue;
        const fv = frMapL[key];
        const ev = enMapL[key];
        if (typeof fv !== 'string' || typeof ev !== 'string') continue;
        if (fv === ev && fv.length > 5) identicalLaravel.push({ key, fr: fv, en: ev });
    }
    identicalLaravel.sort((a, b) => a.key.localeCompare(b.key));

    const missingByLocaleVue = {};
    const missingRowsVue = [];
    for (const loc of LOCALES) {
        const present = localeMapsVue[loc];
        const missing = diffMissing(usedUniqueVue, present).sort();
        missingByLocaleVue[loc] = missing.length;
        for (const key of missing) {
            missingRowsVue.push({
                locale: loc,
                key,
                first_seen_in_file: keyFirstFileVue.get(key) ?? '',
            });
        }
    }

    const missingByLocaleLaravel = {};
    const missingRowsLaravel = [];
    for (const loc of LOCALES) {
        const present = localeMapsLaravel[loc];
        const missing = diffMissing(usedUniqueLaravel, present).sort();
        missingByLocaleLaravel[loc] = missing.length;
        for (const key of missing) {
            missingRowsLaravel.push({
                locale: loc,
                key,
                first_seen_in_file: keyFirstFileLaravel.get(key) ?? '',
            });
        }
    }

    mkdirSync(join(root, REPORTS_DIR), { recursive: true });

    const missingCsvVue = join(root, REPORTS_DIR, `missing_keys_per_locale_VUE_${AUDIT_DATE}.csv`);
    writeCsv(
        missingCsvVue,
        missingRowsVue.map((r) => [r.locale, r.key, r.first_seen_in_file]),
        ['locale', 'key', 'first_seen_in_file'],
    );

    const missingCsvLaravel = join(root, REPORTS_DIR, `missing_keys_per_locale_LARAVEL_${AUDIT_DATE}.csv`);
    writeCsv(
        missingCsvLaravel,
        missingRowsLaravel.map((r) => [r.locale, r.key, r.first_seen_in_file]),
        ['locale', 'key', 'first_seen_in_file'],
    );

    const deadCsvVue = join(root, REPORTS_DIR, `dead_keys_VUE_${AUDIT_DATE}.csv`);
    writeCsv(deadCsvVue, deadKeysVue.map((k) => [k]), ['key']);

    const deadCsvLaravel = join(root, REPORTS_DIR, `dead_keys_LARAVEL_${AUDIT_DATE}.csv`);
    writeCsv(deadCsvLaravel, deadKeysLaravel.map((k) => [k]), ['key']);

    const idCsvVue = join(root, REPORTS_DIR, `identical_fr_en_VUE_${AUDIT_DATE}.csv`);
    writeCsv(
        idCsvVue,
        identicalVue.map((r) => [r.key, r.fr, r.en]),
        ['key', 'fr_value', 'en_value'],
    );

    const idCsvLaravel = join(root, REPORTS_DIR, `identical_fr_en_LARAVEL_${AUDIT_DATE}.csv`);
    writeCsv(
        idCsvLaravel,
        identicalLaravel.map((r) => [r.key, r.fr, r.en]),
        ['key', 'fr_value', 'en_value'],
    );

    console.log(`i18n audit — ${AUDIT_DATE} (Vue + Laravel)\n`);
    console.log('VUE i18n (resources/js/languages):');
    for (const loc of LOCALES) {
        console.log(`  ${loc}: ${missingByLocaleVue[loc]} missing`);
    }
    console.log(
        `  Used: ${usedUniqueVue.length} | Dead: ${deadKeysVue.length} | Identical fr=en: ${identicalVue.length}\n`,
    );

    console.log('LARAVEL i18n (lang/):');
    for (const loc of LOCALES) {
        console.log(`  ${loc}: ${missingByLocaleLaravel[loc]} missing`);
    }
    console.log(
        `  Used: ${usedUniqueLaravel.length} | Dead: ${deadKeysLaravel.length} | Identical fr=en: ${identicalLaravel.length}`,
    );
    console.log(
        `  Files parsed: ${laravelStats.filesParsed} | Files failed: ${laravelStats.filesFailed}\n`,
    );
    console.log(`CSV reports written to ${REPORTS_DIR}/`);

    const anyMissing =
        LOCALES.some((loc) => missingByLocaleVue[loc] > 0) ||
        LOCALES.some((loc) => missingByLocaleLaravel[loc] > 0);
    process.exit(anyMissing ? 1 : 0);
}

const __filename = fileURLToPath(import.meta.url);
const isMain = process.argv[1] && resolve(__filename) === resolve(process.argv[1]);
if (isMain) {
    main();
}
