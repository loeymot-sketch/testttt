import { describe, it, expect } from 'vitest';
import { kioskResolveImageSrc, kioskVariationsForAttribute } from '../../resources/js/helpers/kioskMedia';

describe('kioskMedia', () => {
  it('resolves the first usable image source', () => {
    expect(kioskResolveImageSrc(
      null,
      { thumb: '' },
      { image_full_path: 'https://cdn.test/item.png' },
    )).toBe('https://cdn.test/item.png');
  });

  it('returns null when no image source exists', () => {
    expect(kioskResolveImageSrc({}, null, undefined)).toBeNull();
  });

  it('returns variations for both string and numeric attribute keys', () => {
    const item = {
      variations: {
        '7': [{ id: 1, name: 'Sauce' }],
      },
    };

    expect(kioskVariationsForAttribute(item, 7)).toEqual([{ id: 1, name: 'Sauce' }]);
  });
});
