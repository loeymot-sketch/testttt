// shared.jsx — Shared UI atoms for Le Cayenne

const { useState, useEffect, useRef, Fragment } = React;

// [test-e2e fix A-001 round-2 2026-05-11] gate dev affordance
// Dev mode flag — true when URL has ?dev OR localStorage.lecayenne.dev === 'true'.
// Use this to hide demo OTP codes, debug UI, etc. from production-facing users.
const LC_IS_DEV = (() => {
  try {
    if (typeof window === 'undefined') return false;
    const hasParam = new URLSearchParams(window.location.search).has('dev');
    const hasLS = window.localStorage && window.localStorage.getItem('lecayenne.dev') === 'true';
    return hasParam || hasLS;
  } catch (_e) { return false; }
})();
window.LC = window.LC || {};
window.LC.isDev = LC_IS_DEV;

// Wordmark logo
// ADV-A11-017 P1 round-3 — accent default switched to --orange-text (#C2410C
// = 4.86:1 on white) since Logo is overwhelmingly rendered on white surfaces
// in mobile (login/OTP/confirm). Callers on dark backgrounds explicitly pass
// accent='var(--orange)' or accent='var(--yellow)' for visual emphasis.
function Logo({ size = 22, color = '#0A0A0A', accent = '#C2410C' }) {
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontFamily: 'var(--font-display)', textTransform: 'uppercase', letterSpacing: '0.02em', lineHeight: 1, fontSize: size, fontWeight: 400 }}>
      <span style={{ color }}>LE</span>
      <span style={{ color: accent }}>CAYENNE</span>
    </div>
  );
}

// Stamp/seal mark used as small flourish
function Stamp({ size = 28 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 28 28">
      <circle cx="14" cy="14" r="13" fill="none" stroke="currentColor" strokeWidth="1"/>
      <text x="14" y="17" textAnchor="middle" fontFamily="var(--font-display)" fontSize="11" fontWeight="700" fill="currentColor">LC</text>
    </svg>
  );
}

// Bottom tab bar — A11-001/F-004/S-004/A-007 P1 closed round-2 2026-05-11.
// <div onClick> → <button aria-pressed aria-label> + role="tablist" wrapper.
// axe button-name CRITICAL violation resolved ; keyboard nav now works (button
// is natively focusable + Enter/Space activatable).
function TabBar({ active, onChange }) {
  const tabs = [
    { id: 'home', label: 'Accueil', icon: I.Home },
    { id: 'menu', label: 'Menu', icon: I.Menu },
    { id: 'orders', label: 'Commandes', icon: I.Receipt },
    { id: 'profile', label: 'Profil', icon: I.User },
  ];
  return (
    <div className="lc-tabs" role="tablist" aria-label="Navigation principale">
      {tabs.map(t => {
        const isActive = active === t.id;
        return (
          <button
            key={t.id}
            type="button"
            role="tab"
            aria-selected={isActive}
            aria-label={t.label}
            className={`lc-tab ${isActive ? 'lc-tab--active' : ''}`}
            onClick={() => onChange(t.id)}
          >
            <t.icon size={22} sw={isActive ? 2.2 : 1.7} />
            <span>{t.label}</span>
            <span className="lc-tab-dot" />
          </button>
        );
      })}
    </div>
  );
}

// Image-slot wrapper that respects React.
// Phase 6.A 2026-05-11 (real assets) — if `src` is provided, render a real <img>
// with object-fit:cover + emoji fallback on error. Falls back to the legacy
// <image-slot> drag-and-drop placeholder only when no src is provided.
function Slot({ id, w, h, radius = 14, placeholder = 'photo', shape, style = {}, src, alt, fallbackEmoji }) {
  const sharedBoxStyle = {
    display: 'block',
    width: w === undefined ? '100%' : w,
    height: h,
    borderRadius: radius,
    overflow: 'hidden',
    ...style,
  };
  if (src) {
    return (
      <div style={{ ...sharedBoxStyle, background: 'var(--gray-1)', position: 'relative' }} aria-label={alt || placeholder}>
        <img
          src={src}
          alt={alt || placeholder}
          loading="lazy"
          decoding="async"
          onError={(e) => {
            // Strict fallback chain: hide the broken img and let the emoji span (rendered below) take its place
            e.currentTarget.style.display = 'none';
          }}
          style={{
            display: 'block',
            width: '100%',
            height: '100%',
            objectFit: 'cover',
            objectPosition: '50% 50%',
          }}
        />
        {fallbackEmoji && (
          <span aria-hidden="true" style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 32, pointerEvents: 'none', opacity: 0.001 }}>{fallbackEmoji}</span>
        )}
      </div>
    );
  }
  // Legacy image-slot drag-drop fallback (no src wired — used by dev tooling only)
  return (
    <image-slot
      slot-id={id}
      placeholder={placeholder}
      shape={shape}
      style={sharedBoxStyle}
    />
  );
}

// Generic floating sticky CTA at bottom
function StickyCTA({ children, bg = 'var(--ink)', color = '#fff', extraBottom = 84 }) {
  return (
    <div style={{ position: 'absolute', left: 16, right: 16, bottom: extraBottom, zIndex: 9 }}>
      <button className="lc-btn" style={{ background: bg, color, width: '100%', height: 60, fontSize: 14 }}>{children}</button>
    </div>
  );
}

// Header bar at top of content screens
function ScreenHeader({ left, center, right, style = {}, safeTop = true }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: `${safeTop ? 14 : 14}px 16px 12px`, position: 'relative', zIndex: 5, ...style }}>
      <div style={{ width: 40, display: 'flex' }}>{left}</div>
      <div style={{ flex: 1, display: 'flex', justifyContent: 'center' }}>{center}</div>
      <div style={{ width: 40, display: 'flex', justifyContent: 'flex-end' }}>{right}</div>
    </div>
  );
}

// F-003 / A11-004 P1 closed round-3 — keyboard activation helper for div+onClick
// surfaces. Wires Enter/Space → click. Apply with onKeyDown={lcTapKey(handler)}.
// Pair with role="button" tabIndex={0} className="lc-tap" + aria-label on the
// div. CSS press feedback + focus ring at styles.css `.lc-tap` rules.
window.lcTapKey = function lcTapKey(handler) {
  return function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      handler(e);
    }
  };
};

// Round icon button — A11-002/A11-010/S-003 P1 closed round-2 2026-05-11.
// Accepts ariaLabel (camelCase) AND `aria-label` (string-key passthrough via
// rest-spread) so callsites already using `aria-label="..."` work too.
// Default size bumped 40→44 to meet WCAG 2.5.5 minimum touch target (A11-012).
function IconBtn({ children, onClick, size = 44, bg = 'var(--gray-1)', color = 'var(--ink)', style = {}, ariaLabel, ...rest }) {
  return (
    <button
      onClick={onClick}
      aria-label={ariaLabel}
      {...rest}
      style={{ width: size, height: size, borderRadius: 999, border: 0, background: bg, color, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', ...style }}
    >
      {children}
    </button>
  );
}

// Marquee category banner
// ADV-A11-017 P1 round-3 — default `color` switched #fff → var(--ink) for
// 7.4:1 contrast on --orange (was 3.11:1 fail). Callers wanting white text
// on a darker bg pass color="#fff" explicitly.
function Marquee({ items, bg = 'var(--orange)', color = 'var(--ink)' }) {
  const set = (
    <div className="lc-marquee-track">
      {[...items, ...items].map((it, i) => (
        <div key={i} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '0 18px', fontFamily: 'var(--font-display)', fontSize: 18, textTransform: 'uppercase', whiteSpace: 'nowrap', color }}>
          {it}
          <span style={{ width: 6, height: 6, borderRadius: 999, background: color, opacity: 0.7, marginLeft: 12 }}/>
        </div>
      ))}
    </div>
  );
  return (
    <div className="lc-marquee" style={{ background: bg, padding: '12px 0' }}>
      {set}
    </div>
  );
}

// Onboarding pagination dots
function Dots({ count, active, color = '#0A0A0A' }) {
  return (
    <div style={{ display: 'flex', gap: 6 }}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} style={{ width: i === active ? 22 : 6, height: 6, borderRadius: 999, background: i === active ? color : 'rgba(0,0,0,0.2)', transition: 'all 0.2s' }}/>
      ))}
    </div>
  );
}

// QR Code SVG (simple deterministic mock)
function QRMock({ size = 200, value = 'LECAY-LOYALTY' }) {
  // Generate pseudo-random pattern from value
  const cells = 25;
  const cellSize = size / cells;
  const grid = [];
  let seed = 0;
  for (let c of value) seed = (seed * 31 + c.charCodeAt(0)) % 100000;
  const rand = (i, j) => {
    const v = Math.sin((i + 1) * (j + 1) * (seed + 1)) * 10000;
    return (v - Math.floor(v)) > 0.55;
  };
  for (let i = 0; i < cells; i++) {
    for (let j = 0; j < cells; j++) {
      if (rand(i, j)) grid.push({ i, j });
    }
  }
  // Corner finder pattern
  const finder = (x, y) => (
    <g key={`f${x}-${y}`}>
      <rect x={x*cellSize} y={y*cellSize} width={cellSize*7} height={cellSize*7} fill="#0A0A0A"/>
      <rect x={(x+1)*cellSize} y={(y+1)*cellSize} width={cellSize*5} height={cellSize*5} fill="#fff"/>
      <rect x={(x+2)*cellSize} y={(y+2)*cellSize} width={cellSize*3} height={cellSize*3} fill="#0A0A0A"/>
    </g>
  );
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ background: '#fff' }}>
      {grid.map((c, k) => {
        // Skip finder areas
        if ((c.i < 7 && c.j < 7) || (c.i < 7 && c.j > 17) || (c.i > 17 && c.j < 7)) return null;
        return <rect key={k} x={c.j*cellSize} y={c.i*cellSize} width={cellSize} height={cellSize} fill="#0A0A0A"/>;
      })}
      {finder(0, 0)}
      {finder(0, 18)}
      {finder(18, 0)}
    </svg>
  );
}

Object.assign(window, { Logo, Stamp, TabBar, Slot, StickyCTA, ScreenHeader, IconBtn, Marquee, Dots, QRMock });
