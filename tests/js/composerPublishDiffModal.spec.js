import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

import axios from 'axios';
import ComposerPublishDiffModal from '../../resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue';

function diffPayload(overrides = {}) {
    return {
        is_clean: false,
        added: [],
        removed: [],
        modified: [],
        ...overrides,
    };
}

function mountComponent(props = {}) {
    return mount(ComposerPublishDiffModal, {
        props: {
            profileId: 42,
            isOpen: true,
            ...props,
        },
        global: {
            mocks: {
                $t: (key) => key,
            },
        },
    });
}

describe('ComposerPublishDiffModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('test_modal_fetches_diff_on_open', async () => {
        axios.get.mockResolvedValueOnce({ data: diffPayload() });
        const wrapper = mountComponent({ isOpen: false, profileId: 77 });

        expect(axios.get).not.toHaveBeenCalled();

        await wrapper.setProps({ isOpen: true });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(axios.get).toHaveBeenCalledWith('admin/composer/profiles/77/diff');
    });

    it('test_modal_shows_no_changes_when_is_clean_true', async () => {
        axios.get.mockResolvedValueOnce({
            data: diffPayload({ is_clean: true }),
        });

        const wrapper = mountComponent();
        await flushPromises();

        expect(wrapper.find('[data-testid="diff-no-changes"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="diff-no-changes"]').text()).toContain('studio.composer.diff.no_changes');
    });

    it('test_modal_shows_added_removed_modified_sections_with_counts', async () => {
        axios.get.mockResolvedValueOnce({
            data: diffPayload({
                added: [{ step_key: 'bread' }, { step_key: 'sauce' }],
                removed: [{ step_key: 'old_drink' }],
                modified: [
                    { step_key: 'meat', changed_fields: ['label'] },
                    { step_key: 'extras', changed_fields: ['max_select'] },
                    { step_key: 'dessert', changed_fields: ['visible_on'] },
                ],
            }),
        });

        const wrapper = mountComponent();
        await flushPromises();

        expect(wrapper.find('[data-testid="diff-section-added"]').text()).toContain('studio.composer.diff.added (2)');
        expect(wrapper.find('[data-testid="diff-section-removed"]').text()).toContain('studio.composer.diff.removed (1)');
        expect(wrapper.find('[data-testid="diff-section-modified"]').text()).toContain('studio.composer.diff.modified (3)');
    });

    it('test_confirm_publish_emits_event_and_closes', async () => {
        axios.get.mockResolvedValueOnce({
            data: diffPayload({
                added: [{ step_key: 'bread' }],
            }),
        });

        const wrapper = mountComponent();
        await flushPromises();
        await wrapper.find('[data-testid="diff-confirm-publish-button"]').trigger('click');

        expect(wrapper.emitted('confirm-publish')).toHaveLength(1);
        expect(wrapper.emitted('update:isOpen')?.[0]).toEqual([false]);
    });

    it('test_cancel_closes_modal', async () => {
        axios.get.mockResolvedValueOnce({ data: diffPayload() });

        const wrapper = mountComponent();
        await flushPromises();
        await wrapper.find('[data-testid="diff-cancel-button"]').trigger('click');

        expect(wrapper.emitted('update:isOpen')?.[0]).toEqual([false]);
        expect(wrapper.emitted('confirm-publish')).toBeUndefined();
    });

    it('test_error_state_shows_retry_button', async () => {
        axios.get
            .mockRejectedValueOnce({
                response: {
                    data: { message: 'diff_failed' },
                },
            })
            .mockResolvedValueOnce({ data: diffPayload({ is_clean: true }) });

        const wrapper = mountComponent();
        await flushPromises();

        expect(wrapper.find('[data-testid="diff-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="diff-error"]').text()).toContain('diff_failed');
        expect(wrapper.find('[data-testid="diff-error"] button').text()).toContain('studio.composer.diff.retry');

        await wrapper.find('[data-testid="diff-error"] button').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(2);
    });
});
