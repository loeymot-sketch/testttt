import { describe, it, expect } from 'vitest';
import {
    flattenJson,
    extractI18nKeysFromVue,
    extractI18nKeysFromBlade,
    diffMissing,
} from '../../tools/i18n/audit_locale_keys.mjs';

describe('P-MEGA-11 i18n audit helpers', () => {
    it('flattenJson merges nested objects into dot keys', () => {
        const flat = flattenJson({ a: { b: 'x', c: 'y' } });
        expect(flat).toEqual({ 'a.b': 'x', 'a.c': 'y' });
    });

    it('flattenJson nested 3 levels', () => {
        const flat = flattenJson({ a: { b: { c: 'x' } } });
        expect(flat).toEqual({ 'a.b.c': 'x' });
    });

    it('extractI18nKeysFromVue finds $t in template', () => {
        const source = `<template>{{ $t('foo.bar') }}</template>`;
        const { staticKeys, dynamicCount } = extractI18nKeysFromVue(source);
        expect(staticKeys).toContain('foo.bar');
        expect(dynamicCount).toBe(0);
    });

    it('extractI18nKeysFromBlade finds __ and @lang', () => {
        const source = `{{ __('hello.world') }} @lang('btn.save')`;
        const { staticKeys, dynamicCount } = extractI18nKeysFromBlade(source);
        expect(staticKeys).toEqual(['hello.world', 'btn.save']);
        expect(dynamicCount).toBe(0);
    });

    it('dynamic key detection: template literal with ${} is skipped from static list', () => {
        const source = '$t(`prefix.${key}`)';
        const { staticKeys, dynamicCount } = extractI18nKeysFromVue(source);
        expect(staticKeys.length).toBe(0);
        expect(dynamicCount).toBe(1);
    });

    it('diffMissing returns keys used but absent from locale map', () => {
        const present = { a: 1, b: 2 };
        expect(diffMissing(['a', 'b', 'c'], present)).toEqual(['c']);
    });
});
