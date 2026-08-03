import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KsVirtualKeyboard from '../../resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue';

const mountOpts = { props: { layout: 'fr', visible: true, modelValue: '' }, global: { mocks: { $t: (k) => k } } };

describe('F5 · KsVirtualKeyboard exposes a Shift key (uppercase + AR shift reachable)', () => {
  it('renders a shift key in the actions row', () => {
    const w = mount(KsVirtualKeyboard, mountOpts);
    expect(w.find('[data-testid="kiosk-vkeyb-shift"]').exists()).toBe(true);
  });

  it('starts on the lowercase layout (a present, A absent)', () => {
    const w = mount(KsVirtualKeyboard, mountOpts);
    const labels = w.findAll('button').map((b) => b.text());
    expect(labels).toContain('a');
    expect(labels).not.toContain('A');
  });

  it('toggling shift switches to the uppercase layout and emits a capital', async () => {
    const w = mount(KsVirtualKeyboard, mountOpts);
    await w.find('[data-testid="kiosk-vkeyb-shift"]').trigger('click');
    const upperA = w.findAll('button').find((b) => b.text() === 'A');
    expect(upperA, 'uppercase A must be reachable after Shift').toBeTruthy();
    await upperA.trigger('click');
    const emitted = w.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    expect(emitted[emitted.length - 1][0]).toBe('A');
  });
});
