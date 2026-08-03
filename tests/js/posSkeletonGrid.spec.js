import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SkeletonGrid from '../../resources/js/components/admin/pos/SkeletonGrid.vue';

describe('SkeletonGrid (T12 POS perceived perf)', () => {
    it('renders `count` placeholder tiles', () => {
        const wrapper = mount(SkeletonGrid, {
            props: { count: 6 },
            global: { mocks: { $t: (k) => k } },
        });
        expect(wrapper.findAll('.skeleton-tile')).toHaveLength(6);
    });

    it('exposes busy state for assistive tech', () => {
        const wrapper = mount(SkeletonGrid, {
            global: { mocks: { $t: (k) => k } },
        });
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.attributes('role')).toBe('status');
    });

    it('defaults to 12 tiles', () => {
        const wrapper = mount(SkeletonGrid, {
            global: { mocks: { $t: (k) => k } },
        });
        expect(wrapper.findAll('.skeleton-tile')).toHaveLength(12);
    });
});
