import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = process.cwd();

function read(file) {
    return fs.readFileSync(path.join(root, file), 'utf8');
}

describe('kiosk wizard composer profile runtime contract', () => {
    it('prioritizes published composer steps before legacy template heuristics', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');
        const activeStepsIndex = source.indexOf('activeSteps()');
        const composerIndex = source.indexOf('const composerSteps = this.composerActiveSteps()');
        const legacyTemplateIndex = source.indexOf('const template = this.effectiveWizardTemplate()');

        expect(activeStepsIndex).toBeGreaterThan(-1);
        expect(composerIndex).toBeGreaterThan(activeStepsIndex);
        expect(legacyTemplateIndex).toBeGreaterThan(composerIndex);
        expect(source).toContain('publishedComposerProfile()');
        expect(source).toContain('composerActiveSteps()');
    });

    it('logs heuristic fallback only when no published composer profile exists', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');
        const analytics = read('resources/js/helpers/kioskAnalytics.js');
        const controller = read('app/Http/Controllers/Frontend/KioskEventController.php');

        expect(source).toContain('trackHeuristicFallback');
        expect(source).toContain('missing_published_composer_profile');
        expect(analytics).toContain('wizard.composer_heuristic_fallback');
        expect(analytics).toContain('export function trackHeuristicFallback');
        expect(controller).toContain('wizard.composer_heuristic_fallback');
    });

    it('does not add frontend delivery or final pricing authority to the wizard', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');

        expect(source).not.toContain('calculateDeliveryChargeFromDistance');
        expect(source).not.toContain('delivery_charge');
        expect(source).not.toContain('priceCalculation');
    });
});
