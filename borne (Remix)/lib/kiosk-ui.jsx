// Composants UI partagés pour la borne
// - ProductArt : placeholder visuellement riche à la place d'une vraie photo
// - KioskChrome : status bar + bottom bar réutilisables
// - Btn, Chip, etc.

const { useState, useEffect, useRef, useMemo, useCallback } = React;

// ── Placeholder "photo produit" — un gradient chaud + un disque d'assiette + une
// emoji géante. Pas de photo = mieux qu'une mauvaise photo.
function ProductArt({ hue = 24, emoji = '🍔', size = 'md', style = {} }) {
  const sizes = {
    sm: { h: 120, fs: 56 },
    md: { h: 200, fs: 96 },
    lg: { h: 280, fs: 140 },
    xl: { h: 420, fs: 220 },
  };
  const s = sizes[size] || sizes.md;
  const bg = `
    radial-gradient(ellipse 80% 60% at 50% 115%, oklch(45% 0.12 ${hue}) 0%, transparent 60%),
    radial-gradient(circle at 50% 55%, oklch(78% 0.14 ${hue}) 0%, oklch(62% 0.16 ${hue}) 45%, oklch(42% 0.14 ${hue}) 100%)
  `;
  return (
    <div style={{
      height: s.h,
      width: '100%',
      background: bg,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      fontSize: s.fs,
      position: 'relative',
      overflow: 'hidden',
      ...style,
    }}>
      {/* "Plate" ring for extra food-photo vibes */}
      <div style={{
        position: 'absolute',
        width: '85%',
        aspectRatio: '1',
        borderRadius: '50%',
        background: 'radial-gradient(circle, transparent 48%, rgba(0,0,0,0.12) 52%, transparent 56%)',
        top: '50%', left: '50%',
        transform: 'translate(-50%, -50%)',
      }}/>
      {/* Subtle grain */}
      <div style={{
        position: 'absolute', inset: 0,
        backgroundImage: 'radial-gradient(rgba(255,255,255,0.06) 1px, transparent 1px)',
        backgroundSize: '3px 3px',
        mixBlendMode: 'overlay',
      }}/>
      <span style={{ position: 'relative', filter: 'drop-shadow(0 6px 12px rgba(0,0,0,0.35))' }}>{emoji}</span>
    </div>
  );
}

// Bottom nav reusable — panier visible partout (menu, wizard, etc.)
function BottomBar({ cartCount, cartTotal, onCartClick, onBack, backLabel = 'Retour', primaryLabel, onPrimary, primaryDisabled, theme }) {
  return (
    <div style={{
      position: 'absolute',
      left: 0, right: 0, bottom: 0,
      padding: '24px 32px',
      background: theme.barBg,
      borderTop: `1px solid ${theme.border}`,
      display: 'flex',
      gap: 20,
      alignItems: 'stretch',
      zIndex: 10,
    }}>
      {onBack && (
        <button onClick={onBack} style={{
          flex: '0 0 auto',
          padding: '0 40px',
          borderRadius: 100,
          border: `2px solid ${theme.border}`,
          background: 'transparent',
          color: theme.text,
          fontSize: 32,
          fontWeight: 600,
          cursor: 'pointer',
          fontFamily: 'inherit',
        }}>← {backLabel}</button>
      )}
      <button onClick={onCartClick} style={{
        flex: 1,
        padding: '24px 32px',
        borderRadius: 100,
        border: 'none',
        background: cartCount > 0 ? theme.accent : theme.surface,
        color: cartCount > 0 ? theme.onAccent : theme.textDim,
        fontSize: 32,
        fontWeight: 700,
        cursor: cartCount > 0 ? 'pointer' : 'default',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        fontFamily: 'inherit',
        boxShadow: cartCount > 0 ? `0 10px 30px ${theme.accent}55` : 'none',
        transition: 'all 200ms',
      }}>
        <span style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <span style={{ fontSize: 38 }}>🛒</span>
          <span>{cartCount > 0 ? `Voir mon panier (${cartCount})` : 'Panier vide'}</span>
        </span>
        {cartCount > 0 && <span>{window.KIOSK_DATA.fmt(cartTotal)}</span>}
      </button>
      {primaryLabel && (
        <button onClick={onPrimary} disabled={primaryDisabled} style={{
          flex: '0 0 auto',
          padding: '0 48px',
          borderRadius: 100,
          border: 'none',
          background: primaryDisabled ? theme.surface : theme.accent,
          color: primaryDisabled ? theme.textDim : theme.onAccent,
          fontSize: 32,
          fontWeight: 700,
          cursor: primaryDisabled ? 'not-allowed' : 'pointer',
          fontFamily: 'inherit',
          boxShadow: primaryDisabled ? 'none' : `0 10px 30px ${theme.accent}66`,
        }}>{primaryLabel} →</button>
      )}
    </div>
  );
}

// Top bar with logo, order type pill, help/lang
function TopBar({ orderType, onLogoClick, theme, step, totalSteps }) {
  return (
    <div style={{
      padding: '28px 32px 20px',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      borderBottom: `1px solid ${theme.border}`,
      background: theme.topBarBg,
    }}>
      <div onClick={onLogoClick} style={{
        display: 'flex', alignItems: 'center', gap: 14, cursor: 'pointer',
      }}>
        <div style={{
          width: 56, height: 56, borderRadius: 14,
          background: theme.accent, color: theme.onAccent,
          display: 'grid', placeItems: 'center',
          fontSize: 28, fontWeight: 900,
          boxShadow: `0 6px 20px ${theme.accent}55`,
        }}>GH</div>
        <div>
          <div style={{ fontSize: 26, fontWeight: 800, color: theme.text, letterSpacing: '-0.01em' }}>GRILL HOUSE</div>
          <div style={{ fontSize: 16, color: theme.textDim, letterSpacing: '0.14em', textTransform: 'uppercase', fontWeight: 600 }}>Burger · Grill</div>
        </div>
      </div>

      {step != null && (
        <div style={{
          display: 'flex', gap: 10, alignItems: 'center',
        }}>
          {Array.from({ length: totalSteps }, (_, i) => (
            <div key={i} style={{
              width: i === step ? 36 : 10, height: 10,
              borderRadius: 6,
              background: i <= step ? theme.accent : theme.border,
              transition: 'all 300ms',
            }}/>
          ))}
        </div>
      )}

      {orderType && (
        <div style={{
          padding: '14px 26px',
          borderRadius: 100,
          background: theme.chip,
          color: theme.text,
          fontSize: 22,
          fontWeight: 600,
          display: 'flex', alignItems: 'center', gap: 12,
          border: `1px solid ${theme.border}`,
        }}>
          <span style={{ fontSize: 24 }}>{orderType === 'dine_in' ? '🍽️' : '🥡'}</span>
          {orderType === 'dine_in' ? 'Sur place' : 'À emporter'}
        </div>
      )}
    </div>
  );
}

// Badge "NOUVEAU", "POPULAIRE", etc.
function Tag({ children, color }) {
  return (
    <div style={{
      display: 'inline-block',
      padding: '4px 12px',
      borderRadius: 6,
      background: color || '#000',
      color: '#fff',
      fontSize: 14,
      fontWeight: 800,
      letterSpacing: '0.08em',
    }}>{children}</div>
  );
}

// Qty stepper
function QtyStepper({ value, onChange, min = 1, max = 20, theme, size = 'md' }) {
  const s = size === 'lg' ? { btn: 72, fs: 40 } : { btn: 56, fs: 28 };
  const btnStyle = (disabled) => ({
    width: s.btn, height: s.btn, borderRadius: '50%',
    border: 'none',
    background: disabled ? theme.surface : theme.accent,
    color: disabled ? theme.textDim : theme.onAccent,
    fontSize: s.fs, fontWeight: 700,
    cursor: disabled ? 'not-allowed' : 'pointer',
    display: 'grid', placeItems: 'center',
    fontFamily: 'inherit',
    lineHeight: 1,
  });
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 20 }}>
      <button style={btnStyle(value <= min)} disabled={value <= min} onClick={() => onChange(value - 1)}>−</button>
      <div style={{ fontSize: s.fs + 8, fontWeight: 800, color: theme.text, minWidth: 48, textAlign: 'center' }}>{value}</div>
      <button style={btnStyle(value >= max)} disabled={value >= max} onClick={() => onChange(value + 1)}>+</button>
    </div>
  );
}

Object.assign(window, { ProductArt, BottomBar, TopBar, Tag, QtyStepper });
