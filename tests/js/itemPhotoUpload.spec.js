import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));

import axios from 'axios';
import ItemPhotoUpload from '../../resources/js/components/admin/items/ItemPhotoUpload.vue';

function mountComponent(props = {}) {
    return mount(ItemPhotoUpload, {
        props: {
            itemId: 42,
            ...props,
        },
        global: {
            mocks: {
                $t: (key) => key,
            },
        },
    });
}

function imageFile(options = {}) {
    return new File([options.body ?? 'image-bytes'], options.name ?? 'photo.png', {
        type: options.type ?? 'image/png',
    });
}

describe('ItemPhotoUpload', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('test_renders_idle_state_initially', () => {
        const wrapper = mountComponent();

        expect(wrapper.classes()).not.toContain('is-dragover');
        expect(wrapper.text()).toContain('studio.image.upload_idle');
    });

    it('test_drag_over_class_applied_on_dragover', async () => {
        const wrapper = mountComponent();

        await wrapper.trigger('dragover');

        expect(wrapper.vm.uploadState).toBe('dragover');
        expect(wrapper.classes()).toContain('is-dragover');
    });

    it('test_drag_leave_returns_to_idle', async () => {
        const wrapper = mountComponent();

        await wrapper.trigger('dragover');
        await wrapper.trigger('dragleave');

        expect(wrapper.vm.uploadState).toBe('idle');
    });

    it('test_uploading_state_during_axios_call', async () => {
        const wrapper = mountComponent();
        axios.post.mockImplementationOnce(() => new Promise(() => {}));

        wrapper.vm.upload(imageFile());
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.uploadState).toBe('uploading');
        expect(wrapper.find('.is-uploading').exists()).toBe(true);
    });

    it('test_success_emits_upload_success_with_data', async () => {
        const wrapper = mountComponent();
        const responseData = { url: '/storage/items/photo.webp' };
        axios.post.mockResolvedValueOnce({ data: responseData });

        await wrapper.vm.upload(imageFile({ type: 'image/webp' }));
        await flushPromises();

        expect(wrapper.vm.uploadState).toBe('success');
        expect(wrapper.vm.previewUrl).toBe(responseData.url);
        expect(wrapper.emitted('upload-success')?.[0]).toEqual([responseData]);
    });

    it('test_error_state_on_axios_failure_with_retry_button', async () => {
        const wrapper = mountComponent();
        axios.post.mockRejectedValueOnce({
            response: {
                data: { message: 'upload_failed' },
            },
        });

        await wrapper.vm.upload(imageFile());
        await flushPromises();

        expect(wrapper.vm.uploadState).toBe('error');
        expect(wrapper.vm.errorMessage).toBe('upload_failed');
        expect(wrapper.find('button').text()).toContain('studio.image.upload_retry');
        expect(wrapper.emitted('upload-error')?.[0]).toEqual(['upload_failed']);
    });

    it('test_validation_oversize_rejected_client_side_no_http_call', async () => {
        const wrapper = mountComponent({ maxSizeBytes: 1 });

        await wrapper.vm.upload(imageFile({ body: 'too-large' }));

        expect(wrapper.vm.uploadState).toBe('error');
        expect(wrapper.vm.errorMessage).toBe('studio.image.upload_oversize');
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('test_validation_invalid_mime_rejected_client_side', async () => {
        const wrapper = mountComponent();

        await wrapper.vm.upload(imageFile({ name: 'photo.gif', type: 'image/gif' }));

        expect(wrapper.vm.uploadState).toBe('error');
        expect(wrapper.vm.errorMessage).toBe('studio.image.upload_invalid_mime');
        expect(axios.post).not.toHaveBeenCalled();
    });
});
