/**
 * Vitest n’expose pas require.context (webpack) — nécessaire pour charger resources/js/i18n.js.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const langDir = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/languages');

globalThis.require = {
    context(_dir, _deep, regExp) {
        const keys = readdirSync(langDir)
            .filter((f) => regExp.test(f))
            .map((f) => './' + f);
        const fn = (key) => JSON.parse(readFileSync(join(langDir, key.replace(/^\.\//, '')), 'utf8'));
        fn.keys = () => keys;
        fn.resolve = (k) => k;
        fn.id = langDir;
        return fn;
    },
};
