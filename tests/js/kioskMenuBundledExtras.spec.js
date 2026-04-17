import { describe, it, expect } from 'vitest';
import { kioskIsBundledFritesMenuUpgradeExtra } from '../../resources/js/helpers/kioskMenuBundledExtras';

const menuItem = { has_menu: true };

describe('kioskIsBundledFritesMenuUpgradeExtra', () => {
  it('ignores items without has_menu', () => {
    const res = kioskIsBundledFritesMenuUpgradeExtra(
      { name: 'Frites Cheddar', convert_price: 1 },
      { has_menu: false },
    );
    expect(res).toBe(false);
  });

  it('ignores sauces by group_label', () => {
    const res = kioskIsBundledFritesMenuUpgradeExtra(
      { name: 'Ketchup', group_label: 'sauce', convert_price: 0.5 },
      menuItem,
    );
    expect(res).toBe(false);
  });

  it('detects frites + cheddar upgrade', () => {
    expect(
      kioskIsBundledFritesMenuUpgradeExtra(
        { name: 'Frites Cheddar', convert_price: 1 },
        menuItem,
      ),
    ).toBe(true);
  });

  it('detects frites + cheddar + crispy upgrade', () => {
    expect(
      kioskIsBundledFritesMenuUpgradeExtra(
        { name: 'Frites Cheddar Crispy', convert_price: 2 },
        menuItem,
      ),
    ).toBe(true);
  });

  it('detects group_label menu/frites upgrades', () => {
    expect(
      kioskIsBundledFritesMenuUpgradeExtra(
        { name: 'Portion XL', group_label: 'Menu frites upgrade', convert_price: 1.5 },
        menuItem,
      ),
    ).toBe(true);
  });

  it('does not flag regular paid supplements', () => {
    expect(
      kioskIsBundledFritesMenuUpgradeExtra(
        { name: 'Bacon', convert_price: 1 },
        menuItem,
      ),
    ).toBe(false);
    expect(
      kioskIsBundledFritesMenuUpgradeExtra(
        { name: 'Supplément viande', convert_price: 2 },
        menuItem,
      ),
    ).toBe(false);
  });

  it('returns false for zero-priced extras', () => {
    expect(
      kioskIsBundledFritesMenuUpgradeExtra(
        { name: 'Frites Cheddar', convert_price: 0 },
        menuItem,
      ),
    ).toBe(false);
  });
});
