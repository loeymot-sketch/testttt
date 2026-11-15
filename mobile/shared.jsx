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

// Reset only on screen/step transitions, before paint; ordinary rerenders keep
// the customer's position. A scoped ref also works in the multi-phone preview.
function useScreenScrollReset(screenKey) {
  const screenRef = useRef(null);
  React.useLayoutEffect(() => {
    const screen = screenRef.current;
    if (!screen) return;
    [screen, ...screen.querySelectorAll('.lc-screen, [data-screen-scroll]')].forEach(node => {
      node.scrollTop = 0;
      node.scrollLeft = 0;
    });
  }, [screenKey]);
  return screenRef;
}

// Canonical Le Cayenne brand asset. The text fallback keeps the identity legible
// if the shared image is unavailable during an offline/local preview.
const BRAND_LOGO_SRC = 'assets/brand/lecayenne-logo.webp';

// Wordmark logo
// ADV-A11-017 P1 round-3 — accent default switched to --orange-text (#C2410C
// = 4.86:1 on white) since Logo is overwhelmingly rendered on white surfaces
// in mobile (login/OTP/confirm). Callers on dark backgrounds explicitly pass
// accent='var(--orange)' or accent='var(--yellow)' for visual emphasis.
function Logo({ size = 22, color = '#0A0A0A', accent = '#C2410C' }) {
  const [imageFailed, setImageFailed] = useState(false);
  const wrapperStyle = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', lineHeight: 1 };
  if (!imageFailed) {
    return (
      <span role="img" aria-label="Le Cayenne" style={wrapperStyle}>
        <img
          src={BRAND_LOGO_SRC}
          alt="Le Cayenne"
          onError={() => setImageFailed(true)}
          style={{ display: 'block', width: Math.max(52, size * 4.5), height: 'auto', objectFit: 'contain' }}
        />
      </span>
    );
  }
  return (
    <span role="img" aria-label="Le Cayenne" style={{ ...wrapperStyle, gap: 8, fontFamily: 'var(--font-display)', textTransform: 'uppercase', letterSpacing: '0.02em', fontSize: size, fontWeight: 400 }}>
      <span style={{ color }}>LE</span>
      <span style={{ color: accent }}>CAYENNE</span>
    </span>
  );
}

// Bottom tab bar — A11-001/F-004/S-004/A-007 P1 closed round-2 2026-05-11.
// <div onClick> → <button aria-pressed aria-label> + role="tablist" wrapper.
// axe button-name CRITICAL violation resolved ; keyboard nav now works (button
// is natively focusable + Enter/Space activatable).
// [UI/UX 2026-11-15] `badge` = nombre d'articles au panier, porté par l'onglet Menu : le client qui
// revient à l'accueil voit qu'un panier l'attend. L'onglet actif a un coussin jaune (CSS .lc-tab__icon).
function TabBar({ active, onChange, badge = 0 }) {
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
        const n = t.id === 'menu' ? (Number(badge) || 0) : 0;
        return (
          <button
            key={t.id}
            type="button"
            role="tab"
            aria-selected={isActive}
            aria-label={t.label + (n > 0 ? ` — ${n} article${n > 1 ? 's' : ''} au panier` : '')}
            className={`lc-tab ${isActive ? 'lc-tab--active' : ''}`}
            onClick={() => onChange(t.id)}
          >
            <span className="lc-tab__icon" aria-hidden="true">
              <t.icon size={22} sw={isActive ? 2.2 : 1.7} />
              {n > 0 && <span key={n} className="lc-tab__badge" data-testid="tab-cart-badge">{n > 9 ? '9+' : n}</span>}
            </span>
            <span>{t.label}</span>
            <span className="lc-tab-dot" />
          </button>
        );
      })}
    </div>
  );
}

// [UI/UX 2026-11-15] Retour tactile court (Android ; iOS l'ignore sans bruit). Jamais sous
// prefers-reduced-motion : le client qui coupe le mouvement coupe aussi les vibrations.
window.lcHaptic = function lcHaptic(ms = 8) {
  try {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (navigator.vibrate) navigator.vibrate(ms);
  } catch (_e) { /* non supporté */ }
};

// Un nombre qui saute quand il change : remonté (clé = valeur) pour rejouer l'animation CSS .lc-bump.
function Bump({ value, children, className = '', style }) {
  return <span key={String(value)} className={'lc-bump ' + className} style={style}>{children === undefined ? value : children}</span>;
}

// Neutral image state for assets that are not available locally yet. It is
// deliberately brand-neutral: missing Hawaï/Fuze Tea/Perrier/Orangina
// packshots must never be represented by another drink or an emoji.
function NeutralAsset({ label = 'Photo indisponible', style = {} }) {
  return (
    <span
      className="lc-image-fallback"
      role="img"
      aria-label={label}
      style={style}
    >
      <span aria-hidden="true" className="lc-image-fallback__mark" />
    </span>
  );
}

// Image-slot wrapper that respects React.
// Phase 6.A 2026-05-11 (real assets) — if `src` is provided, render a real <img>
// with explicit fit for product cutouts. A broken/missing asset falls back to a
// neutral state, never to an emoji or another brand. Heroes keep the default
// cover fit.
function Slot({ id, w, h, radius = 14, placeholder = 'photo', shape, style = {}, src, alt, fallbackEmoji: _fallbackEmoji, fit = 'cover' }) {
  const [imageFailed, setImageFailed] = useState(false);
  // [UI/UX 2026-11-15] Lueur d'attente puis fondu : une photo qui « apparaît » plutôt qu'un trou gris qui saute.
  const [loaded, setLoaded] = useState(false);
  const imgRef = (el) => { if (el && el.complete && el.naturalWidth > 0 && !loaded) setLoaded(true); };
  const sharedBoxStyle = {
    display: 'block',
    width: w === undefined ? '100%' : w,
    height: h,
    borderRadius: radius,
    overflow: 'hidden',
    ...style,
  };
  if (src && !imageFailed) {
    return (
      <div data-image-slot="true" data-image-src={src} data-image-state="loaded" className={loaded ? '' : 'lc-shimmer'} style={{ ...sharedBoxStyle, background: 'var(--gray-1)', position: 'relative', pointerEvents: 'none' }} aria-label={alt || placeholder}>
        <img
          ref={imgRef}
          className={'lc-img-fade' + (loaded ? ' is-loaded' : '')}
          src={src}
          alt={alt === undefined ? placeholder : alt}
          loading="lazy"
          decoding="async"
          onLoad={() => setLoaded(true)}
          onError={() => setImageFailed(true)}
          style={{
            display: 'block',
            width: '100%',
            height: '100%',
            objectFit: fit,
            objectPosition: '50% 50%',
          }}
        />
      </div>
    );
  }
  if (src && imageFailed) {
    return (
      <div data-image-slot="true" data-image-src={src} data-image-state="fallback" style={{ ...sharedBoxStyle, background: 'var(--gray-1)', position: 'relative', pointerEvents: 'none' }}>
        <NeutralAsset label={`${alt || placeholder} — photo indisponible`} />
      </div>
    );
  }
  // Neutral fallback when no asset has been wired yet. This path is kept for
  // dev tooling and makes missing product art explicit without inventing it.
  return (
    <div data-image-slot="true" data-image-state="fallback" style={{ ...sharedBoxStyle, background: 'var(--gray-1)', position: 'relative', pointerEvents: 'none' }}>
      <NeutralAsset label={`${alt || placeholder} — photo indisponible`} />
    </div>
  );
}

window.NeutralAsset = NeutralAsset;
window.Slot = Slot;

// Header bar at top of content screens
function ScreenHeader({ left, center, right, style = {} }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 16px 12px', position: 'relative', zIndex: 5, ...style }}>
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

Object.assign(window, { BRAND_LOGO_SRC, useScreenScrollReset, Logo, TabBar, Slot, ScreenHeader, IconBtn, Marquee, Dots, Bump });
