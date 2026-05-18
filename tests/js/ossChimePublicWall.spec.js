/**
 * [GOAL Round 2 Impl C — P0-OSS-01 + P1-OSS-01 — 2026-05-18]
 *
 * P0-OSS-01 — Chime dead on public TV wall
 *   `PreparingAndReadyComponent.vue` lines 115-116 register
 *   `pointerdown`/`keydown` `{ once: true }` audio-unlock listeners. A public
 *   TV wall sees zero operator interaction → listeners never fire →
 *   `_audioCtx` stays null forever → chime is silent on the only surface that
 *   needs it (the customer wall). Agent 4 finding `[OSS-B-02]`.
 *
 *   Heal (Option C, owner-validated): the screen has TWO audiences
 *     - operator-attended (admin session, `authBranchId() > 0`) → chime SHOULD
 *       play, audio listeners SHOULD be wired (operator clicks Vue routes).
 *     - public TV wall (no session, `authBranchId() <= 0`) → chime SHOULD be
 *       skipped gracefully and visual `.oss-ready-flash` notification SHALL
 *       remain the sole feedback channel (already attested by Agent 4 §3).
 *   Mirror the existing `subscribeEcho()` early-return idiom (line 233:
 *   `if (branchId <= 0) return;`) inside `_playReadySound()` AND around the
 *   `_audioInitListener` registration in `mounted()`.
 *
 * P1-OSS-01 — PRÊT green/white contrast 2.6:1 fails WCAG AA
 *   Previous fill `text-[#2AC769]` luminance ratio = 2.22:1 vs white
 *   background (computed via WCAG 2.x relative-luminance formula).
 *   Heal: `text-[#0E7C3A]` luminance ratio = 5.30:1 vs white → passes AA
 *   (4.5:1 threshold) and AA-large; preserves the green character that
 *   distinguishes the column from the red PRÉPARATION column.
 *
 * Style note: static `SOURCE.toContain()` assertions + an isolated logic test
 * mirror the proven `ossWakeLockOnMount.spec.js` pattern — no Vue mount, no
 * Echo/Pusher/Vuex setup, fast + deterministic.
 */
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue'),
    'utf8'
);

describe('OSS chime public-wall fallback (P0-OSS-01)', () => {
    it('_playReadySound gates on operator presence (authBranchId > 0)', () => {
        // The chime must early-return on the public wall (no audio context will
        // ever be unlocked, no operator gesture is ever fired). Mirror the
        // existing `subscribeEcho` idiom (line ~233 `if (branchId <= 0) return`).
        expect(SOURCE).toMatch(/_playReadySound\s*\(\)\s*\{[\s\S]*?if\s*\(\s*this\.authBranchId\s*\(\s*\)\s*<=\s*0\s*\)\s*return/);
    });

    it('_audioInitListener registration is skipped on public wall', () => {
        // Listeners that can never fire are dead code on the public TV wall
        // (no pointerdown / keydown ever fires). Wrap the registration in the
        // same authBranchId() guard.
        expect(SOURCE).toMatch(/if\s*\(\s*this\.authBranchId\s*\(\s*\)\s*>\s*0\s*\)\s*\{[\s\S]*?addEventListener\(\s*['"]pointerdown['"]/);
    });

    it('preserves the audio context lazy-init pattern (no regression on iter15 C-034)', () => {
        // The fix MUST keep the round-7 lazy-init mitigation that stopped the
        // console autoplay-warning flood: AudioContext built once on first
        // gesture, not per chime call.
        expect(SOURCE).toContain('lazy-init pattern');
        expect(SOURCE).toContain('_audioCtx');
        expect(SOURCE).toMatch(/window\.AudioContext\s*\|\|\s*window\.webkitAudioContext/);
    });

    it('isolated logic: public mode (authBranchId=0) skips chime, operator mode (>0) plays it', () => {
        // Mirror _playReadySound branching against the same audio-context shim
        // the component uses. authBranchId() === 0 → return before any
        // oscillator is created. authBranchId() > 0 + ctx present + running →
        // create 4 oscillators (matches the C-major arpeggio in the file).
        const oscillators = [];
        const fakeOsc = () => ({
            connect: vi.fn(),
            frequency: {},
            start: vi.fn(() => oscillators.push('start')),
            stop: vi.fn(),
        });
        const fakeGain = () => ({
            connect: vi.fn(),
            gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() },
        });
        const buildCtx = () => ({
            state: 'running',
            currentTime: 0,
            createOscillator: vi.fn(fakeOsc),
            createGain: vi.fn(fakeGain),
            destination: {},
            resume: vi.fn(),
        });

        function play(branchId, ctx) {
            // ↓ Inline mirror of the heal: early-return when no operator.
            if (branchId <= 0) return;
            if (!ctx) return;
            if (ctx.state === 'suspended') {
                ctx.resume?.().catch(() => {});
                if (ctx.state !== 'running') return;
            }
            [523, 659, 784, 1047].forEach((freq, i) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = freq;
                osc.start(ctx.currentTime + i * 0.15);
            });
        }

        // Public TV wall (no operator).
        const publicCtx = buildCtx();
        play(0, publicCtx);
        expect(publicCtx.createOscillator).not.toHaveBeenCalled();
        expect(oscillators).toHaveLength(0);

        // Operator-attended screen (branch staff or admin).
        const operatorCtx = buildCtx();
        play(2, operatorCtx);
        expect(operatorCtx.createOscillator).toHaveBeenCalledTimes(4);
        expect(oscillators).toHaveLength(4);
    });

    it('visual flash channel remains intact on public wall (Agent 4 §3 attested)', () => {
        // The heal MUST NOT touch _markNewReady: the visual `.oss-ready-flash`
        // + `oss-new-ready` bounce is the ONLY notification on the public wall
        // and Agent 4 confirms it already works. Guard against accidental
        // regression: the call to _playReadySound must remain UNCONDITIONAL
        // inside _markNewReady so the gating logic lives in one place (inside
        // _playReadySound) — not duplicated at every caller.
        expect(SOURCE).toMatch(/_markNewReady\s*\(\s*orderId\s*\)\s*\{[\s\S]*?this\._playReadySound\s*\(\s*\);[\s\S]*?this\.newReadyFlash\s*=\s*true/);
    });
});

describe('OSS PRÊT column WCAG AA contrast (P1-OSS-01)', () => {
    it('replaces text-[#2AC769] with WCAG-AA-passing green', () => {
        expect(SOURCE).not.toContain('text-[#2AC769]');
        // #0E7C3A → 5.30:1 contrast vs white background. Computed via WCAG 2.x
        // relative-luminance formula. Passes AA (4.5:1) for normal-weight text
        // and exceeds AAA-large (4.5:1) for the `text-[40px]` queue numbers.
        expect(SOURCE).toContain('text-[#0E7C3A]');
    });

    it('isolated logic: WCAG ratio for new green clears AA threshold', () => {
        // Re-compute the relative luminance to lock the contrast invariant
        // — if a future heal nudges the hex toward lighter green this spec
        // will catch the regression instead of silently re-failing axe.
        const hexToRgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
        const srgbToLin = (c) => {
            const x = c / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        };
        const luminance = ([r, g, b]) =>
            0.2126 * srgbToLin(r) + 0.7152 * srgbToLin(g) + 0.0722 * srgbToLin(b);
        const ratio = (a, b) => {
            const La = luminance(hexToRgb(a));
            const Lb = luminance(hexToRgb(b));
            return (Math.max(La, Lb) + 0.05) / (Math.min(La, Lb) + 0.05);
        };

        expect(ratio('#2AC769', '#FFFFFF')).toBeLessThan(4.5); // Old: FAIL baseline.
        expect(ratio('#0E7C3A', '#FFFFFF')).toBeGreaterThanOrEqual(4.5); // New: PASS.
    });
});
