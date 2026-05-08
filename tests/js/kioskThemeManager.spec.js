/**
 * [Wave Gamma G3 / V2-5] Skinning saisonnier kiosk — theme manager JS specs.
 *
 * Vérifie le contrat documenté côté `kioskThemeManager.js` :
 *  - thème inconnu → fallback 'standard' + warning console.
 *  - apply() pose bien `data-kiosk-theme` sur `<html>`.
 *  - persist() écrit dans localStorage (clé `kiosk_active_theme`).
 *  - load() retourne null si la valeur stockée n'est plus dans la
 *    liste blanche (slug stale).
 *  - initialize() respecte la priorité localStorage > backend > default.
 *  - fetchActiveBranchTheme() gère gracieusement l'absence d'axios et
 *    l'erreur réseau (retourne null sans throw).
 *  - forceRefreshFromBackend() ignore le cache localStorage.
 *
 * Pattern aligné sur kioskBootHealthcheck.spec.js : on stub `window.axios`
 * et on `vi.resetModules()` + dynamic import pour partir de zéro à chaque
 * test (sinon le singleton `default` partage son `activeTheme`).
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const STORAGE_KEY = 'kiosk_active_theme';

function freshImport() {
    vi.resetModules();
    return import('../../resources/js/services/kioskThemeManager.js');
}

function stubAxios(impl) {
    window.axios = {
        get: vi.fn(impl ?? (async () => ({ data: { active_theme: 'standard' } }))),
    };
}

beforeEach(() => {
    // Toujours partir d'un DOM neutre + localStorage vide.
    if (typeof document !== 'undefined' && document.documentElement) {
        document.documentElement.removeAttribute('data-kiosk-theme');
    }
    try {
        localStorage.clear();
    } catch (_) {
        /* happy-dom always provides localStorage */
    }
});

afterEach(() => {
    delete window.axios;
    vi.restoreAllMocks();
});

describe('kioskThemeManager — apply()', () => {
    it('applyThemeSetsDataAttribute', async () => {
        const mod = await freshImport();
        const result = mod.default.apply('halloween');

        expect(result).toBe('halloween');
        expect(document.documentElement.getAttribute('data-kiosk-theme')).toBe('halloween');
        expect(mod.default.activeTheme).toBe('halloween');
    });

    it('unknownThemeFallsBackToStandard', async () => {
        const mod = await freshImport();
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const result = mod.default.apply('mardi-gras-2027');

        expect(result).toBe('standard');
        expect(document.documentElement.getAttribute('data-kiosk-theme')).toBe('standard');
        expect(warnSpy).toHaveBeenCalled();
    });

    it('isSupportedTheme matches the public allowlist', async () => {
        const mod = await freshImport();
        expect(mod.SUPPORTED_THEMES).toContain('standard');
        expect(mod.SUPPORTED_THEMES).toContain('halloween');
        expect(mod.SUPPORTED_THEMES).toContain('christmas');
        expect(mod.default.isSupportedTheme('halloween')).toBe(true);
        expect(mod.default.isSupportedTheme('paques')).toBe(false);
        expect(mod.default.isSupportedTheme(null)).toBe(false);
        expect(mod.default.isSupportedTheme(42)).toBe(false);
    });
});

describe('kioskThemeManager — persist() / load()', () => {
    it('persistsToLocalStorage', async () => {
        const mod = await freshImport();
        mod.default.persist('christmas');

        expect(localStorage.getItem(STORAGE_KEY)).toBe('christmas');
    });

    it('load returns null when storage is empty', async () => {
        const mod = await freshImport();
        expect(mod.default.load()).toBeNull();
    });

    it('load filters stale slugs not in allowlist', async () => {
        localStorage.setItem(STORAGE_KEY, 'fifa-2026-deprecated');
        const mod = await freshImport();
        // Stale slug → load() returns null so the resolution chain falls through
        // to backend / default; never apply an unknown CSS class.
        expect(mod.default.load()).toBeNull();
    });

    it('setTheme applies and persists in one call', async () => {
        const mod = await freshImport();
        const result = mod.default.setTheme('halloween');

        expect(result).toBe('halloween');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('halloween');
        expect(document.documentElement.getAttribute('data-kiosk-theme')).toBe('halloween');
    });
});

describe('kioskThemeManager — initialize()', () => {
    it('initializeUsesLocalStorageFirst', async () => {
        localStorage.setItem(STORAGE_KEY, 'halloween');
        // Backend would say 'christmas' if asked — must NOT be hit.
        stubAxios(async () => ({ data: { active_theme: 'christmas' } }));

        const mod = await freshImport();
        const applied = await mod.default.initialize(42);

        expect(applied).toBe('halloween');
        expect(window.axios.get).not.toHaveBeenCalled();
        expect(document.documentElement.getAttribute('data-kiosk-theme')).toBe('halloween');
    });

    it('initialize falls through to backend when localStorage is empty', async () => {
        stubAxios(async () => ({ data: { active_theme: 'christmas' } }));

        const mod = await freshImport();
        const applied = await mod.default.initialize(42);

        expect(applied).toBe('christmas');
        // [V2-5 Phase 2] window.axios baseURL is `/api` (cf. resources/js/app.js).
// The path MUST be relative (no leading `/api/`) — otherwise axios composes
// `/api/api/admin/...` and the boot fetch silently 404s.
expect(window.axios.get).toHaveBeenCalledWith('admin/kiosk-theme/42');
        expect(document.documentElement.getAttribute('data-kiosk-theme')).toBe('christmas');
    });

    it('initialize falls back to standard when both localStorage and backend are empty', async () => {
        stubAxios(async () => ({ data: {} }));

        const mod = await freshImport();
        const applied = await mod.default.initialize(42);

        expect(applied).toBe('standard');
    });

    it('initialize falls back to standard when backend returns unknown slug', async () => {
        stubAxios(async () => ({ data: { active_theme: 'mardi-gras' } }));

        const mod = await freshImport();
        const applied = await mod.default.initialize(42);

        expect(applied).toBe('standard');
    });

    it('initialize survives missing branchId without crashing', async () => {
        const mod = await freshImport();
        const applied = await mod.default.initialize(null);
        expect(applied).toBe('standard');
    });

    it('initialize survives axios reject (backend down) gracefully', async () => {
        stubAxios(async () => {
            throw new Error('network down');
        });

        const mod = await freshImport();
        const applied = await mod.default.initialize(42);
        expect(applied).toBe('standard');
    });

    it('initialize survives missing window.axios entirely', async () => {
        // Pre-login boot scenario — axios not yet bound.
        delete window.axios;

        const mod = await freshImport();
        const applied = await mod.default.initialize(42);
        expect(applied).toBe('standard');
    });
});

describe('kioskThemeManager — forceRefreshFromBackend()', () => {
    it('forceRefreshFromBackend ignores cached localStorage', async () => {
        localStorage.setItem(STORAGE_KEY, 'halloween');
        stubAxios(async () => ({ data: { active_theme: 'christmas' } }));

        const mod = await freshImport();
        const applied = await mod.default.forceRefreshFromBackend(42);

        expect(applied).toBe('christmas');
        // [V2-5 Phase 2] window.axios baseURL is `/api` (cf. resources/js/app.js).
// The path MUST be relative (no leading `/api/`) — otherwise axios composes
// `/api/api/admin/...` and the boot fetch silently 404s.
expect(window.axios.get).toHaveBeenCalledWith('admin/kiosk-theme/42');
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });
});
