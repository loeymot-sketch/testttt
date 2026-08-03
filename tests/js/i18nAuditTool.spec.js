import { describe, it, expect } from 'vitest';
import { join, resolve } from 'node:path';
import {
    flattenJson,
    extractI18nKeysFromVue,
    extractI18nKeysFromBlade,
    diffMissing,
    parsePhpLocaleFile,
    resolveBladeKeyToPhp,
} from '../../tools/i18n/audit_locale_keys.mjs';

const repoRoot = resolve(process.cwd());

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

    it('parsePhpLocaleFile — flat', () => {
        const src = `<?php return ['a'=>'1','b'=>'2'];`;
        expect(parsePhpLocaleFile(src, '')).toEqual({ a: '1', b: '2' });
    });

    it('parsePhpLocaleFile — nested', () => {
        const src = `<?php return ['a'=>['b'=>'x','c'=>'y']];`;
        expect(parsePhpLocaleFile(src, '')).toEqual({ 'a.b': 'x', 'a.c': 'y' });
    });

    it('extractI18nKeysFromBlade — mixed Blade string order', () => {
        const source = `@lang('foo.bar') {{ __('baz.qux') }}`;
        const { staticKeys, dynamicCount } = extractI18nKeysFromBlade(source);
        expect(staticKeys).toEqual(['foo.bar', 'baz.qux']);
        expect(dynamicCount).toBe(0);
    });

    it('resolveBladeKeyToPhp — auth.failed vs unknown', () => {
        const langFr = join(repoRoot, 'lang/fr');
        expect(resolveBladeKeyToPhp('auth.failed', langFr)).toBe(true);
        expect(resolveBladeKeyToPhp('unknown.x', langFr)).toBe(false);
    });

    it('11 stripVueComments: HTML comment $t not counted', () => {
        const source =
            `<template><!-- $t('comment.fake') -->{{ $t('real.key') }}</template>`;
        const { staticKeys } = extractI18nKeysFromVue(source);
        expect(staticKeys).toContain('real.key');
        expect(staticKeys).not.toContain('comment.fake');
    });

    it('12 t with options (Composition API)', () => {
        const source = `t('key.with.opts', { count: 2 })`;
        const { staticKeys } = extractI18nKeysFromVue(source);
        expect(staticKeys).toContain('key.with.opts');
    });

    it('13 template literal multiline $t backtick', () => {
        const source = '$t(`part1\npart2`)';
        const { staticKeys, dynamicCount } = extractI18nKeysFromVue(source);
        expect(dynamicCount).toBe(0);
        expect(staticKeys).toContain('part1\npart2');
    });
});
