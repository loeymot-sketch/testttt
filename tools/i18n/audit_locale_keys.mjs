/**
 * i18n locale key audit — scans Vue/Blade/JS for static translation keys vs JSON locales.
 */
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const AUDIT_DATE = '2026-04-20';
const LOCALES_DIR = 'resources/js/languages';
const COMPONENTS_DIR = 'resources/js/components';
const BLADE_DIR = 'resources/views';
const JS_SCAN_ROOT = 'resources/js';
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
 * @param {string} source
 * @returns {{ staticKeys: string[], dynamicCount: number }}
 */
export function extractI18nKeysFromVue(source) {
    const staticKeys = [];
    let dynamicCount = 0;

    const reString = /\$t(?:c)?\(\s*['"]([^'"]+)['"]/g;
    let m;
    while ((m = reString.exec(source)) !== null) {
        staticKeys.push(m[1]);
    }

    const reI18n = /\$i18n\.t\(\s*['"]([^'"]+)['"]/g;
    while ((m = reI18n.exec(source)) !== null) {
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

    const reUnd = /__\(\s*['"]([^'"]+)['"]/g;
    let m;
    while ((m = reUnd.exec(source)) !== null) {
        staticKeys.push(m[1]);
    }

    const reLang = /@lang\(\s*['"]([^'"]+)['"]/g;
    while ((m = reLang.exec(source)) !== null) {
        staticKeys.push(m[1]);
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

export function main() {
    const root = resolveRepoRoot();
    const vueFiles = [];
    walkDir(join(root, COMPONENTS_DIR), COMPONENTS_DIR, vueFiles, ['.vue']);

    const bladeFiles = [];
    walkDir(join(root, BLADE_DIR), BLADE_DIR, bladeFiles, ['.php']);

    const jsFiles = [];
    walkDir(join(root, JS_SCAN_ROOT), JS_SCAN_ROOT, jsFiles, ['.js']);
    const jsFiltered = jsFiles.filter((p) => {
        const n = p.split(/[/\\]/).join('/');
        return !n.startsWith('resources/js/languages/') && n !== 'resources/js/languages';
    });

    const keyFirstFile = new Map();
    const allStatic = [];
    let dynamicTotal = 0;

    function ingest(fileRel, extractFn) {
        const abs = join(root, fileRel);
        let content;
        try {
            content = readFileSync(abs, 'utf8');
        } catch {
            return;
        }
        const { staticKeys, dynamicCount } = extractFn(content);
        dynamicTotal += dynamicCount;
        for (const k of staticKeys) {
            if (!keyFirstFile.has(k)) keyFirstFile.set(k, fileRel);
            allStatic.push(k);
        }
    }

    for (const f of vueFiles) ingest(f, extractI18nKeysFromVue);
    for (const f of bladeFiles) {
        if (!f.endsWith('.blade.php')) continue;
        ingest(f, extractI18nKeysFromBlade);
    }
    for (const f of jsFiltered) ingest(f, extractI18nKeysFromJs);

    const usedUnique = [...new Set(allStatic)];

    /** @type {Record<string, Record<string, unknown>>} */
    const localeMaps = {};
    for (const loc of LOCALES) {
        const p = join(root, LOCALES_DIR, `${loc}.json`);
        const raw = JSON.parse(readFileSync(p, 'utf8'));
        localeMaps[loc] = flattenJson(raw);
    }

    const allJsonKeys = new Set();
    for (const loc of LOCALES) {
        for (const k of Object.keys(localeMaps[loc])) allJsonKeys.add(k);
    }

    const usedSet = new Set(usedUnique);
    const deadKeys = [...allJsonKeys].filter((k) => !usedSet.has(k)).sort();

    const frMap = localeMaps.fr;
    const enMap = localeMaps.en;
    const identical = [];
    for (const key of Object.keys(frMap)) {
        if (!Object.prototype.hasOwnProperty.call(enMap, key)) continue;
        const fv = frMap[key];
        const ev = enMap[key];
        if (typeof fv !== 'string' || typeof ev !== 'string') continue;
        if (fv === ev && fv.length > 5) identical.push({ key, fr: fv, en: ev });
    }
    identical.sort((a, b) => a.key.localeCompare(b.key));

    const missingRows = [];
    const missingByLocale = {};

    for (const loc of LOCALES) {
        const present = localeMaps[loc];
        const missing = diffMissing(usedUnique, present).sort();
        missingByLocale[loc] = missing.length;
        for (const key of missing) {
            missingRows.push({
                locale: loc,
                key,
                first_seen_in_file: keyFirstFile.get(key) ?? '',
            });
        }
    }

    mkdirSync(join(root, REPORTS_DIR), { recursive: true });

    const missingCsv = join(root, REPORTS_DIR, `missing_keys_per_locale_${AUDIT_DATE}.csv`);
    writeCsv(
        missingCsv,
        missingRows.map((r) => [r.locale, r.key, r.first_seen_in_file]),
        ['locale', 'key', 'first_seen_in_file'],
    );

    const deadCsv = join(root, REPORTS_DIR, `dead_keys_${AUDIT_DATE}.csv`);
    writeCsv(
        deadCsv,
        deadKeys.map((k) => [k]),
        ['key'],
    );

    const idCsv = join(root, REPORTS_DIR, `identical_fr_en_${AUDIT_DATE}.csv`);
    writeCsv(
        idCsv,
        identical.map((r) => [r.key, r.fr, r.en]),
        ['key', 'fr_value', 'en_value'],
    );

    console.log(`i18n audit — ${AUDIT_DATE}`);
    for (const loc of LOCALES) {
        console.log(`${loc}.json : ${missingByLocale[loc]} missing / ${deadKeys.length} dead`);
    }
    console.log(`Total used keys : ${usedUnique.length}`);
    console.log(`Total dynamic keys (skipped) : ${dynamicTotal}`);
    console.log(`CSV reports written to ${REPORTS_DIR}/`);

    const anyMissing = LOCALES.some((loc) => missingByLocale[loc] > 0);
    process.exit(anyMissing ? 1 : 0);
}

const __filename = fileURLToPath(import.meta.url);
const isMain = process.argv[1] && resolve(__filename) === resolve(process.argv[1]);
if (isMain) {
    main();
}
