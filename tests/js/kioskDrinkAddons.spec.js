import { describe, it, expect } from 'vitest';
import {
  kioskDrinkAddonRowsFromItem,
  kioskIsDrinkAddonName,
} from '../../resources/js/helpers/kioskDrinkAddons';

describe('kioskDrinkAddons', () => {
  it('excludes Menu and frites from drink rows', () => {
    const item = {
      addons: [
        { addon_item_name: 'Menu' },
        { addon_item_name: 'Frites à part' },
        { addon_item_name: 'Coca-Cola', addon_item_id: 1 },
      ],
    };
    const rows = kioskDrinkAddonRowsFromItem(item);
    expect(rows).toHaveLength(1);
    expect(rows[0].addon_item_name).toBe('Coca-Cola');
  });

  it('kioskIsDrinkAddonName matches common drinks', () => {
    expect(kioskIsDrinkAddonName('Eau plate')).toBe(true);
    expect(kioskIsDrinkAddonName('Jus d’orange')).toBe(true);
    expect(kioskIsDrinkAddonName('Nuggets')).toBe(false);
  });
});
