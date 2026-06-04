import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [GOAL-2026-05-29 BTN-P2 BROKEN-FUNCTION-FIX] The OSS customer wall's only
 * interactive control is the navbar "Plein écran" toggle (shown only on
 * order-status-screen). It called document.addEventListener('mousemove',
 * handleMouseMove) but handleMouseMove was NEVER defined anywhere (refactor
 * leftover) -> ReferenceError thrown right after requestFullscreen() on every
 * click, killing the cursor-reveal UX and spamming the console. Surface-button
 * agent-army audit, P2. Fix: removed the 3 dangling references (the reveal was
 * already non-functional). This sentinel forbids any undefined-handler mousemove
 * listener from creeping back into the navbar.
 */
const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/layouts/backend/BackendNavbarComponent.vue'),
  'utf-8',
);

describe('OSS fullscreen toggle — no undefined handleMouseMove listener (ReferenceError fix)', () => {
  it('does NOT register a mousemove listener bound to the undefined handleMouseMove', () => {
    expect(src).not.toMatch(/EventListener\(\s*['"]mousemove['"]\s*,\s*handleMouseMove\s*\)/);
  });

  it('still defines the fullScreen toggle handler (control not removed)', () => {
    expect(src).toMatch(/fullScreen:\s*function/);
    expect(src).toMatch(/toggleFullscreen:\s*function/);
  });
});
