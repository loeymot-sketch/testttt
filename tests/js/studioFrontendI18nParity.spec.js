import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect } from 'vitest';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import de from '../../resources/js/languages/de.json';
import bn from '../../resources/js/languages/bn.json';
import ar from '../../resources/js/languages/ar.json';

const flatten = (obj, prefix = '') => {
    const out = {};
    for (const key of Object.keys(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (typeof obj[key] === 'object' && obj[key] !== null && !Array.isArray(obj[key])) {
            Object.assign(out, flatten(obj[key], path));
        } else {
            out[path] = true;
        }
    }
    return out;
};

const extractStudioKeys = (lang) => {
    if (!lang.studio) return [];
    return Object.keys(flatten(lang.studio, 'studio'));
};

const extractStudioUsagesFromFile = (relativePath) => {
    const source = readFileSync(resolve(process.cwd(), relativePath), 'utf-8');
    const regex = /\$(?:t|tc)\(\s*['"]studio\.([^'"]+)['"]/g;
    const usedKeys = new Set();
    let match;

    while ((match = regex.exec(source)) !== null) {
        usedKeys.add(match[1]);
    }

    return usedKeys;
};

const expectStudioUsagesDefinedInFr = (relativePaths) => {
    const definedKeys = new Set(Object.keys(flatten(fr.studio ?? {})));
    const missing = [];

    for (const relativePath of relativePaths) {
        for (const key of extractStudioUsagesFromFile(relativePath)) {
            if (!definedKeys.has(key)) {
                missing.push(`${relativePath}: studio.${key}`);
            }
        }
    }

    expect(missing, `Missing studio.* keys in fr.json: ${missing.join(', ')}`).toEqual([]);
};

describe('Frontend i18n parity for studio.* namespace', () => {
    const locales = { fr, en, de, bn, ar };
    const reference = extractStudioKeys(fr).sort();

    it('all 5 locales have a studio namespace', () => {
        expect(fr.studio).toBeDefined();
        expect(en.studio).toBeDefined();
        expect(de.studio).toBeDefined();
        expect(bn.studio).toBeDefined();
        expect(ar.studio).toBeDefined();
    });

    for (const [code, lang] of Object.entries(locales)) {
        if (code === 'fr') continue;
        it(`${code} has identical studio.* keys as fr`, () => {
            const actual = extractStudioKeys(lang).sort();
            expect(actual).toEqual(reference);
        });
    }

    it('contains the β1 required keys', () => {
        const required = [
            'studio.composer.diff.title',
            'studio.composer.diff.added',
            'studio.composer.diff.removed',
            'studio.composer.diff.modified',
            'studio.composer.diff.no_changes',
            'studio.composer.diff.confirm_publish',
            'studio.composer.diff.cancel',
            'studio.composer.conflict.title',
            'studio.composer.conflict.message',
            'studio.composer.conflict.reload',
            'studio.image.upload_idle',
            'studio.image.upload_drag_over',
            'studio.image.upload_uploading',
            'studio.image.upload_success',
            'studio.image.upload_error',
            'studio.image.upload_retry',
            'studio.image.upload_invalid_mime',
            'studio.image.upload_oversize',
        ];
        const present = extractStudioKeys(fr);
        for (const key of required) {
            expect(present).toContain(key);
        }
    });
});

describe('Studio i18n leak prevention sentinel', () => {
    it('all studio.* keys used in CatalogStudioComponent.vue are defined in fr.json', () => {
        expectStudioUsagesDefinedInFr([
            'resources/js/components/admin/items/CatalogStudioComponent.vue',
        ]);
    });

    it('also covers ItemPhotoUpload.vue and Composer Diff Modal usages', () => {
        expectStudioUsagesDefinedInFr([
            'resources/js/components/admin/items/ItemPhotoUpload.vue',
            'resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue',
        ]);
    });
});
