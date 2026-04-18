/**
 * Kiosk Phase 9.1.9 — Regression guard on the `idle_warning_shown`
 * analytics event.
 *
 * Avant ce fix, `KioskInactivityOverlayComponent.start()` envoyait
 * `idle_warning` alors que la whitelist `ALLOWED_EVENTS` contient
 * `idle_warning_shown`. L'événement était donc silently droppé par le
 * filtre, et l'analytique était aveugle aux overlays. Ce test :
 *  1. Vérifie que la whitelist contient bien `idle_warning_shown`.
 *  2. Vérifie que l'event exact `idle_warning` n'y figure pas (sinon
 *     une régression pourrait réintroduire la duplication).
 *  3. Lit le code source du composant pour confirmer qu'il appelle
 *     bien `track('idle_warning_shown', …)`. Lecture texte, pas de
 *     mount (éviter dépendance Vue2/3 runtime).
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import { KIOSK_ANALYTICS_ALLOWED_EVENTS } from '../../resources/js/helpers/kioskAnalytics.js';

describe('Kiosk Phase 9.1.9 — idle_warning_shown event wiring', () => {
    it('la whitelist contient idle_warning_shown et PAS idle_warning', () => {
        expect(KIOSK_ANALYTICS_ALLOWED_EVENTS).toContain('idle_warning_shown');
        expect(KIOSK_ANALYTICS_ALLOWED_EVENTS).not.toContain('idle_warning');
    });

    it('KioskInactivityOverlayComponent.vue utilise le nom canonique', () => {
        const file = path.join(
            __dirname,
            '../../resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue',
        );
        const src = fs.readFileSync(file, 'utf8');
        expect(src).toContain("kioskAnalytics.track('idle_warning_shown'");
        // Négatif : plus de call avec l'ancien nom court.
        expect(src).not.toMatch(/kioskAnalytics\.track\('idle_warning'[^_]/);
    });
});
