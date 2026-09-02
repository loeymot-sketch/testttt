/**
 * @vitest-environment jsdom
 *
 * [2026-09-01] Ce banc DOIT tourner sous jsdom, pas sous le `happy-dom` global
 * de vitest.config.mjs. Mesuré, mêmes entrées, DOMPurify 3.4.14 :
 *
 *   happy-dom : '<p>hi</p><script>alert(1)</script>'  ->  'hi<script>alert(1)</script>'
 *               '<a href="https://example.com">ex</a>' ->  'ex'
 *   jsdom     : '<p>hi</p><script>alert(1)</script>'  ->  '<p>hi</p>'
 *               '<a href="https://example.com">ex</a>' ->  '<a href="https://example.com">ex</a>'
 *
 * happy-dom annonce pourtant `DOMPurify.isSupported === true` : l'assainisseur ne
 * signale rien, il rend juste l'inverse de ce qu'on lui demande — il retire les
 * balises autorisées et laisse passer <script>. Le navigateur réel se comporte
 * comme jsdom ; la production n'est donc pas concernée. Ce qui l'était, c'est le
 * banc : sous happy-dom il ne pouvait PAS passer, donc il ne pouvait plus
 * distinguer une vraie régression d'assainissement du bruit d'environnement.
 */
import { describe, it, expect } from 'vitest';
import { safeHtml } from '../../resources/js/utils/safeHtml';

describe('safeHtml()', () => {
    it('returns empty string for null/undefined input', () => {
        expect(safeHtml(null)).toBe('');
        expect(safeHtml(undefined)).toBe('');
    });

    it('coerces non-string input safely', () => {
        expect(safeHtml(42)).toBe('42');
        expect(safeHtml(false)).toBe('false');
    });

    it('strips <script> tags completely', () => {
        const out = safeHtml('<p>hi</p><script>alert(1)</script>');
        expect(out).not.toMatch(/<script/i);
        expect(out).not.toContain('alert(1)');
        expect(out).toContain('<p>hi</p>');
    });

    it('strips inline event handlers like onerror / onclick', () => {
        const out = safeHtml('<img src=x onerror="alert(1)"/>');
        expect(out.toLowerCase()).not.toContain('onerror');
        expect(out).not.toContain('alert(1)');
    });

    it('strips <iframe> / <object> / <embed>', () => {
        const out = safeHtml('<iframe src="https://evil.tld"></iframe>');
        expect(out.toLowerCase()).not.toContain('<iframe');
    });

    it('preserves whitelisted rich-text tags from Quill', () => {
        const input = '<p><strong>Bold</strong> <em>italic</em><br/>line</p>';
        const out = safeHtml(input);
        expect(out).toContain('<strong>Bold</strong>');
        expect(out).toContain('<em>italic</em>');
        expect(out).toMatch(/<br\s*\/?>/);
    });

    it('keeps safe href attributes on <a>', () => {
        const out = safeHtml('<a href="https://example.com">ex</a>');
        expect(out).toMatch(/href="https:\/\/example\.com"/);
    });

    it('strips javascript: href (XSS vector)', () => {
        const out = safeHtml('<a href="javascript:alert(1)">x</a>');
        expect(out.toLowerCase()).not.toContain('javascript:');
    });

    it('strips style attributes (disallowed)', () => {
        const out = safeHtml('<p style="color:red">ok</p>');
        expect(out.toLowerCase()).not.toContain('style=');
        expect(out).toContain('ok');
    });
});
