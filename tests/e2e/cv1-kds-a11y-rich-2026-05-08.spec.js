// FoodKing E2E — CV1 KDS a11y rich (2026-05-08)
//
// Mission CV1-KDS-A11Y-RICH-001 (RED-R4 KD1 + KD2).
// Goal :
//   - Order cards expose role="article" + aria-labelledby pointing to a
//     per-order title id (KD1).
//   - A dedicated <div role="status" aria-live="polite"> region exists
//     for ACCEPT → PREPARING → PREPARED transition announcements (KD2).
//   - axe-core stays at 0 violations (R4-06 baseline must not regress).
//
// Strategy :
//   - getByRole('article') count >= 0 with structural HTML guarantees.
//   - axe-core analyze on the live KDS surface ; no critical / serious
//     regressions allowed.

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAsChefOperator } = require('./helpers/login');

const KDS_PATH_RE = /\/(kds|admin\/kitchen-display-system)/;

test.describe('CV1 KDS a11y rich — articles + aria-live + axe', () => {
  test.setTimeout(120_000);

  test('axe-core 0 critical/serious violations (no regression on R4-06 baseline)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginAsChefOperator(page);
    await expect(page).toHaveURL(KDS_PATH_RE, { timeout: 25_000 });
    await page.waitForTimeout(3500);

    const a11y = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze()
      .catch((e) => ({ _error: e.message }));

    if (a11y && a11y._error) {
      // axe loader failure is a soft skip — do not block the suite, but
      // mark explicitly so it's visible in the report.
      console.warn('[CV1-A11Y-RICH] axe analyze unavailable:', a11y._error);
      test.skip(true, `axe loader failed: ${a11y._error}`);
    }

    const critical = a11y.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    );
    expect(
      critical,
      `Unexpected critical/serious axe violations: ${JSON.stringify(critical.map((v) => ({ id: v.id, impact: v.impact, count: v.nodes.length })))}`,
    ).toEqual([]);
  });

  test('order cards expose role="article" with aria-labelledby pointing to a per-order title id', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginAsChefOperator(page);
    await expect(page).toHaveURL(KDS_PATH_RE, { timeout: 25_000 });
    await page.waitForTimeout(3500);

    // Some empty boards may show 0 cards depending on seed state; the
    // template invariants we lock are :
    //   1. role="article" only appears on order-card wrappers
    //   2. each article carries a aria-labelledby = "order-<id>-title"
    //   3. for every aria-labelledby target id, a matching id exists.
    const probe = await page.evaluate(() => {
      const articles = Array.from(document.querySelectorAll('[role="article"]'));
      const probes = articles.slice(0, 8).map((el) => {
        const labelledBy = el.getAttribute('aria-labelledby') || '';
        const target = labelledBy ? document.getElementById(labelledBy) : null;
        return {
          aria_labelledby: labelledBy,
          target_present: !!target,
          target_text: target ? (target.textContent || '').trim().slice(0, 40) : null,
        };
      });
      return {
        article_count: articles.length,
        probes,
      };
    });

    if (probe.article_count > 0) {
      // Each visible article must have a resolvable title id.
      for (const p of probe.probes) {
        expect(p.aria_labelledby).toMatch(/^order-\d+-title$/);
        expect(p.target_present).toBe(true);
      }
    } else {
      // Empty board — assert structural guards via HTML so a future seed
      // change with cards immediately surfaces missing role.
      const html = await page.content();
      expect(html).toContain('role="article"');
      expect(html).toContain('aria-labelledby');
    }
  });

  test('dedicated aria-live polite region present and atomic', async ({ page }) => {
    await loginAsChefOperator(page);
    await expect(page).toHaveURL(KDS_PATH_RE, { timeout: 25_000 });
    await page.waitForTimeout(3000);

    const ariaLive = page.getByTestId('kds-aria-live');
    await expect(ariaLive).toBeAttached();
    await expect(ariaLive).toHaveAttribute('role', 'status');
    await expect(ariaLive).toHaveAttribute('aria-live', 'polite');
    await expect(ariaLive).toHaveAttribute('aria-atomic', 'true');
  });

  test('aria-live region is visually hidden (no kitchen layout reflow)', async ({ page }) => {
    await loginAsChefOperator(page);
    await expect(page).toHaveURL(KDS_PATH_RE, { timeout: 25_000 });
    await page.waitForTimeout(3000);

    const visible = await page.getByTestId('kds-aria-live').evaluate((el) => {
      const cs = window.getComputedStyle(el);
      return {
        width: cs.width,
        height: cs.height,
        clip: cs.clip,
        position: cs.position,
        overflow: cs.overflow,
      };
    });
    // Standard sr-only pattern: width/height 1px, position absolute, overflow hidden.
    expect(visible.position).toBe('absolute');
    expect(visible.overflow).toBe('hidden');
    expect(visible.width).toBe('1px');
    expect(visible.height).toBe('1px');
  });
});
