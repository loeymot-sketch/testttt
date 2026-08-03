import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ComposerVersionConflictBanner from '../../resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue';

function mountComponent(props = {}) {
    return mount(ComposerVersionConflictBanner, {
        props,
        global: {
            mocks: {
                $t: (key) => key,
            },
        },
    });
}

describe('ComposerVersionConflictBanner', () => {
    it('test_banner_hidden_when_not_visible', () => {
        const wrapper = mountComponent({ isVisible: false });

        expect(wrapper.find('[data-testid="composer-version-conflict-banner"]').exists()).toBe(false);
    });

    it('test_banner_visible_with_message_and_versions', () => {
        const wrapper = mountComponent({
            isVisible: true,
            currentVersion: 3,
            expectedVersion: 5,
        });

        const banner = wrapper.find('[data-testid="composer-version-conflict-banner"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('studio.composer.conflict.title');
        expect(banner.text()).toContain('studio.composer.conflict.message');
        expect(banner.text()).toContain('v3 → v5');
    });

    it('test_reload_emits_event', async () => {
        const wrapper = mountComponent({ isVisible: true });

        await wrapper.find('[data-testid="composer-version-conflict-banner-reload"]').trigger('click');

        expect(wrapper.emitted('reload')).toHaveLength(1);
        expect(wrapper.emitted('reload')?.[0]).toEqual([]);
    });
});
