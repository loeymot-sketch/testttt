// Run: node mobile/tests/navigation.spec.js (Node only, no browser/dependencies).
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = file => fs.readFileSync(path.resolve(__dirname, '..', file), 'utf8');
const shared = read('shared.jsx');
const main = read('screens-main.jsx');
const steps = read('screens-item-steps.jsx');
const css = read('styles.css');

// Exercise the actual hook with React's ref/dependency/commit ordering.
const hook = shared.match(/function useScreenScrollReset\(screenKey\) \{[\s\S]*?\n\}/);
assert.ok(hook, 'screen scroll hook exists');
const ref = { current: null };
let previousKey;
let mounted = false;
let commit;
const sandbox = {
  useRef: () => ref,
  React: {
    useLayoutEffect(effect, deps) {
      commit = !mounted || deps[0] !== previousKey ? effect : () => {};
      previousKey = deps[0];
      mounted = true;
    },
  },
};
vm.createContext(sandbox);
vm.runInContext(hook[0], sandbox);
const node = () => ({ scrollTop: 420, scrollLeft: 30 });
const list = node();
const wizard = node();
const otherScreen = node();
const screen = Object.assign(node(), {
  querySelectorAll(selector) {
    assert.equal(selector, '.lc-screen, [data-screen-scroll]');
    return [list, wizard];
  },
});
function render(key, element = screen) {
  const result = sandbox.useScreenScrollReset(key);
  result.current = element;
  commit();
}
render('home');
for (const element of [screen, list, wizard]) {
  assert.equal(element.scrollTop, 0, 'mount resets vertical scroll before paint');
  assert.equal(element.scrollLeft, 0, 'mount resets horizontal scroll');
}
assert.equal(otherScreen.scrollTop, 420, 'other preview screens stay untouched');
list.scrollTop = 250;
render('home');
assert.equal(list.scrollTop, 250, 'quantity/filter rerenders preserve position');
render('item:0');
assert.equal(list.scrollTop, 0, 'navigation resets the destination');
wizard.scrollTop = 350;
render('item:1');
assert.equal(wizard.scrollTop, 0, 'next wizard step resets its reused scroller');
wizard.scrollTop = 200;
render('item:0');
assert.equal(wizard.scrollTop, 0, 'back navigation also resets');
render('unmounted', null);

// All in-scope top-level screens use the hook and bind its ref, including errors.
for (const name of ['Home', 'Menu', 'Item', 'Cart', 'Confirm', 'Orders', 'Profile', 'Loyalty']) {
  const component = main.split(`function Screen${name}(`)[1].split('\nfunction ')[0];
  assert.match(component, /const screenRef = useScreenScrollReset\(/, `${name} uses reset hook`);
  for (const root of component.matchAll(/<div[^>]*data-screen-label=[^>]+>/g)) {
    assert.match(root[0], /ref=\{screenRef\}/, `${name} binds its screen ref`);
  }
}
assert.match(steps, /useScreenScrollReset\(`\$\{itemId\}:\$\{stepIndex\}`\)/);
assert.match(steps, /<div data-screen-scroll /, 'wizard scroller is explicitly identified');
assert.match(steps, /function ScreenItemDirectAdd[\s\S]*?useScreenScrollReset\(item.id\)/);

// Product cutouts must fit fully; Slot's default hero cropping stays unchanged.
let productSlots = 0;
for (const source of [main, steps]) {
  for (const match of source.matchAll(/<Slot\b[^>]+\/>/g)) {
    if (/src=\{(?:it|item)\.image\}/.test(match[0])) {
      productSlots++;
      assert.match(match[0], /fit="contain"/, 'product image cannot be cropped');
    }
  }
}
assert.equal(productSlots, 8, 'home, menu, detail, cart, upsell, recap and direct-add covered');
assert.match(shared, /fit = 'cover'/, 'hero default remains cover');
assert.match(main, /src=\{featured.hero\}[^>]*fit="contain"/, 'existing signature hero fit preserved');
assert.match(shared, /position: 'relative', pointerEvents: 'none'/, 'image layers cannot intercept clicks');
assert.match(css, /\.lc-device > \[data-screen-label\][^}]*contain: paint/, 'artwork stays inside its screen');
assert.match(css, /\[data-lc-decoration\], \.lc-marquee\s*\{ pointer-events: none;/);
assert.match(css, /\.lc-device \.rdw-step\s*\{ animation: none;/);
console.log('PASS: scroll lifecycle, screen wiring, 8 product slots, hero fit and click-layer guards');
