/* ==========================================================================
   FoodKing Kiosk — Atoms & molecules partagés
   ========================================================================== */

// ---- Design tokens (utilisés en inline style pour éviter collisions) -----
const fk = {
  red:       "#E8001C",
  redDark:   "#B8000F",
  redSoft:   "#FFF0F2",
  white:     "#FFFFFF",
  bg:        "#FFFBF5",   // warm appétissant (validé par le user)
  surface:   "#FFFFFF",
  surfaceAlt:"#F7F3EC",
  text:      "#1A1A1A",
  textSoft:  "#5A5A5A",
  textMute:  "#9A9A9A",
  border:    "#EEE6D9",
  success:   "#16A34A",
  warning:   "#F59E0B",
  error:     "#DC2626",
  shadowCard:"0 4px 18px rgba(20,20,20,0.06)",
  shadowLift:"0 12px 32px rgba(20,20,20,0.12)",
  shadowCTA: "0 10px 24px rgba(232,0,28,0.28)",
};

// ---- Primary CTA (rouge FoodKing) ----------------------------------------
function FkButton({ children, onClick, variant = "primary", disabled, fullWidth, style, size = "lg", icon }) {
  const base = {
    border: "none",
    borderRadius: 28,
    fontWeight: 700,
    cursor: disabled ? "not-allowed" : "pointer",
    display: "inline-flex",
    alignItems: "center",
    justifyContent: "center",
    gap: 12,
    letterSpacing: 0.3,
    textTransform: "uppercase",
    transition: "transform 140ms ease, box-shadow 140ms ease",
    width: fullWidth ? "100%" : "auto",
    opacity: disabled ? 0.42 : 1,
    fontFamily: "inherit",
  };
  const sizes = {
    lg:  { height: 110, padding: "0 42px", fontSize: 30 },
    md:  { height: 82,  padding: "0 28px", fontSize: 22 },
    sm:  { height: 60,  padding: "0 22px", fontSize: 18 },
  };
  const variants = {
    primary: {
      background: fk.red,
      color: "#fff",
      boxShadow: disabled ? "none" : fk.shadowCTA,
    },
    secondary: {
      background: "#fff",
      color: fk.text,
      border: `2px solid ${fk.border}`,
      boxShadow: "0 2px 6px rgba(0,0,0,0.04)",
    },
    ghost: {
      background: "transparent",
      color: fk.textSoft,
      border: `2px solid ${fk.border}`,
    },
    danger: {
      background: "#fff",
      color: fk.error,
      border: `2px solid ${fk.error}`,
    },
    dark: {
      background: fk.text,
      color: "#fff",
    },
  };
  return (
    <button
      onClick={disabled ? undefined : onClick}
      disabled={disabled}
      style={{ ...base, ...sizes[size], ...variants[variant], ...style }}
      onMouseDown={(e) => !disabled && (e.currentTarget.style.transform = "scale(0.97)")}
      onMouseUp={(e) => (e.currentTarget.style.transform = "")}
      onMouseLeave={(e) => (e.currentTarget.style.transform = "")}
    >
      {icon && <span style={{ fontSize: "1.2em", lineHeight: 1 }}>{icon}</span>}
      {children}
    </button>
  );
}

// ---- Badge --------------------------------------------------------------
function FkBadge({ children, color = "red", style }) {
  const palette = {
    red:    { bg: fk.red, fg: "#fff" },
    green:  { bg: fk.success, fg: "#fff" },
    amber:  { bg: fk.warning, fg: "#fff" },
    gray:   { bg: "#EEE6D9", fg: fk.text },
    soft:   { bg: fk.redSoft, fg: fk.red },
  };
  const p = palette[color] || palette.red;
  return (
    <span style={{
      background: p.bg, color: p.fg,
      fontSize: 14, fontWeight: 800,
      padding: "6px 12px",
      borderRadius: 999,
      textTransform: "uppercase",
      letterSpacing: 0.6,
      ...style,
    }}>{children}</span>
  );
}

// ---- Placeholder photo produit (gros rond couleur + emoji) ---------------
function FkProductImage({ emoji, size = 240, bg = fk.surfaceAlt, style }) {
  return (
    <div style={{
      width: size, height: size,
      borderRadius: "50%",
      background: `radial-gradient(circle at 30% 28%, ${fk.white}, ${bg})`,
      display: "flex", alignItems: "center", justifyContent: "center",
      fontSize: size * 0.55,
      flexShrink: 0,
      boxShadow: "inset 0 -4px 12px rgba(0,0,0,0.04)",
      ...style,
    }}>
      {emoji}
    </div>
  );
}

// ---- Plus button overlay (rouge rond avec +) - comme GUR KEBAB -----------
function FkPlusBtn({ onClick, selected, number, size = 64, style }) {
  return (
    <button onClick={onClick} style={{
      width: size, height: size,
      borderRadius: "50%",
      background: selected ? fk.text : fk.red,
      border: `4px solid ${fk.white}`,
      color: "#fff",
      fontSize: size * 0.48,
      fontWeight: 800,
      display: "flex", alignItems: "center", justifyContent: "center",
      cursor: "pointer",
      boxShadow: "0 6px 14px rgba(232,0,28,0.35)",
      flexShrink: 0,
      ...style,
    }}>
      {number ? number : (selected ? "✓" : "+")}
    </button>
  );
}

// ---- Quantity Selector ----------------------------------------------------
function FkQty({ value, onChange, min = 1, max = 20 }) {
  const btn = {
    width: 72, height: 72,
    borderRadius: "50%",
    background: "#fff",
    border: `2px solid ${fk.border}`,
    fontSize: 32,
    fontWeight: 700,
    color: fk.text,
    cursor: "pointer",
    display: "flex", alignItems: "center", justifyContent: "center",
  };
  return (
    <div style={{ display: "inline-flex", alignItems: "center", gap: 20 }}>
      <button style={btn} onClick={() => onChange(Math.max(min, value - 1))} disabled={value <= min}>−</button>
      <div style={{ fontSize: 36, fontWeight: 800, minWidth: 48, textAlign: "center" }}>{value}</div>
      <button style={btn} onClick={() => onChange(Math.min(max, value + 1))} disabled={value >= max}>+</button>
    </div>
  );
}

// ---- Progress bar (dots avec labels, style GUR KEBAB) --------------------
function FkProgress({ steps, current }) {
  // Affichage max 5 étapes visibles autour de la courante (fenêtre glissante)
  const visibleCount = Math.min(steps.length, 5);
  let start = Math.max(0, current - 2);
  let end = Math.min(steps.length, start + visibleCount);
  start = Math.max(0, end - visibleCount);
  const visible = steps.slice(start, end);

  return (
    <div style={{ padding: "24px 32px 28px", background: fk.surface, borderBottom: `1px solid ${fk.border}` }}>
      {/* Icons row */}
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12, marginBottom: 14 }}>
        {visible.map((s, i) => {
          const absIdx = start + i;
          const isActive = absIdx === current;
          const isDone = absIdx < current;
          return (
            <div key={absIdx} style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", gap: 8 }}>
              <div style={{
                width: 84, height: 84,
                borderRadius: "50%",
                background: "#fff",
                border: `3px solid ${isActive ? fk.red : isDone ? fk.red : fk.border}`,
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 34,
                opacity: isDone ? 0.6 : 1,
                position: "relative",
              }}>
                {s.icon}
                {isDone && (
                  <div style={{
                    position: "absolute", top: -4, right: -4,
                    width: 28, height: 28, borderRadius: "50%",
                    background: fk.success, color: "#fff",
                    fontSize: 16, fontWeight: 800,
                    display: "flex", alignItems: "center", justifyContent: "center",
                    border: "2px solid #fff",
                  }}>✓</div>
                )}
              </div>
              <div style={{
                fontSize: 13, fontWeight: 700,
                color: isActive ? fk.red : fk.textSoft,
                textTransform: "uppercase", letterSpacing: 0.6,
                textAlign: "center", minHeight: 32, lineHeight: 1.2,
              }}>
                {s.label}
              </div>
            </div>
          );
        })}
      </div>

      {/* Segmented numeric bar — window si > 7 étapes */}
      <div style={{ display: "flex", alignItems: "center", gap: 6, padding: "0 20px" }}>
        {(() => {
          const max = 7;
          let segStart = 0, segEnd = steps.length;
          if (steps.length > max) {
            segStart = Math.max(0, current - 3);
            segEnd = Math.min(steps.length, segStart + max);
            segStart = Math.max(0, segEnd - max);
          }
          const windowed = steps.slice(segStart, segEnd).map((_, i) => segStart + i);
          return windowed.map((absIdx, localIdx) => {
            const isDone = absIdx < current;
            const isActive = absIdx === current;
            return (
              <React.Fragment key={absIdx}>
                <div style={{
                  width: 42, height: 42, borderRadius: "50%",
                  background: isActive ? fk.red : isDone ? fk.red : "#fff",
                  border: `2px solid ${isActive || isDone ? fk.red : fk.border}`,
                  color: isActive || isDone ? "#fff" : fk.textMute,
                  fontWeight: 800, fontSize: 16,
                  display: "flex", alignItems: "center", justifyContent: "center",
                  flexShrink: 0,
                }}>{absIdx + 1}</div>
                {localIdx < windowed.length - 1 && (
                  <div style={{ flex: 1, height: 3, background: absIdx < current ? fk.red : fk.border, borderRadius: 2 }} />
                )}
              </React.Fragment>
            );
          });
        })()}
        {steps.length > 7 && (
          <div style={{ marginLeft: 10, fontSize: 12, fontWeight: 700, color: fk.textMute, whiteSpace: "nowrap" }}>
            {current + 1}/{steps.length}
          </div>
        )}
      </div>
    </div>
  );
}

// ---- Wizard footer sticky (Précédent / Suivant / total) ------------------
function FkWizardFooter({ canNext, onPrev, onNext, onAbandon, total, nextLabel = "Suivant", hint }) {
  return (
    <div style={{
      position: "absolute", bottom: 0, left: 0, right: 0,
      background: fk.surface,
      borderTop: `1px solid ${fk.border}`,
      padding: "20px 28px 28px",
      boxShadow: "0 -8px 24px rgba(0,0,0,0.04)",
    }}>
      {hint && (
        <div style={{
          background: fk.redSoft, color: fk.red,
          padding: "10px 18px", borderRadius: 14,
          fontSize: 18, fontWeight: 700,
          textAlign: "center", marginBottom: 14,
        }}>⚠ {hint}</div>
      )}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 16 }}>
        <button onClick={onAbandon} style={{
          background: "transparent", border: "none",
          color: fk.textSoft, fontSize: 17, fontWeight: 700,
          textTransform: "uppercase", letterSpacing: 0.5,
          cursor: "pointer", padding: "8px 4px",
          textDecoration: "underline",
        }}>Abandonner l'article</button>
        <div style={{ fontSize: 26, fontWeight: 800, color: fk.text }}>
          Total <span style={{ color: fk.red, marginLeft: 8 }}>{total.toFixed(2).replace(".", ",")} €</span>
        </div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1.4fr", gap: 14 }}>
        <FkButton variant="secondary" onClick={onPrev} size="lg">‹ Précédent</FkButton>
        <FkButton variant="primary" onClick={onNext} disabled={!canNext} size="lg">{nextLabel} ›</FkButton>
      </div>
    </div>
  );
}

// ---- Wizard header (titre + close) ---------------------------------------
function FkWizardHeader({ title, onClose }) {
  return (
    <div style={{
      padding: "26px 28px 0",
      display: "flex", alignItems: "center", justifyContent: "space-between",
      gap: 12,
    }}>
      <div style={{ width: 64 }} />
      <div style={{
        fontSize: 26, fontWeight: 800,
        textTransform: "uppercase", letterSpacing: 1,
        color: fk.text, textAlign: "center", flex: 1,
      }}>{title}</div>
      <button onClick={onClose} style={{
        width: 64, height: 64, borderRadius: "50%",
        background: "#fff", border: `2px solid ${fk.border}`,
        fontSize: 28, cursor: "pointer",
        display: "flex", alignItems: "center", justifyContent: "center",
        color: fk.textSoft,
      }}>×</button>
    </div>
  );
}

// ---- Kiosk frame (châssis physique — optionnel via tweak) ----------------
function FkKioskFrame({ children, showFrame = true }) {
  if (!showFrame) {
    return (
      <div style={{
        width: 1080, height: 1920,
        background: fk.bg,
        position: "relative",
        overflow: "hidden",
        borderRadius: 8,
      }}>{children}</div>
    );
  }
  return (
    <div style={{
      width: 1180, padding: 40,
      background: "linear-gradient(180deg, #1a1a1a, #0d0d0d)",
      borderRadius: 48,
      boxShadow: "0 30px 80px rgba(0,0,0,0.5), inset 0 0 0 2px rgba(232,0,28,0.9), inset 0 0 0 6px #1a1a1a",
      position: "relative",
    }}>
      {/* Top LED strip */}
      <div style={{
        position: "absolute", top: 10, left: 60, right: 60, height: 4,
        background: fk.red, borderRadius: 2, opacity: 0.8,
      }} />
      {/* Camera */}
      <div style={{
        position: "absolute", top: 20, left: "50%", transform: "translateX(-50%)",
        width: 12, height: 12, borderRadius: "50%",
        background: "#222", border: "2px solid #333",
      }} />
      <div style={{
        width: 1080, height: 1920,
        background: fk.bg,
        borderRadius: 12,
        position: "relative",
        overflow: "hidden",
        boxShadow: "inset 0 0 0 1px rgba(0,0,0,0.1)",
      }}>
        {children}
      </div>
      {/* Card reader / printer hint */}
      <div style={{
        marginTop: 24,
        display: "flex", justifyContent: "center", gap: 28,
        color: "#666", fontSize: 12, fontWeight: 700, letterSpacing: 2,
      }}>
        <span>■ TPE</span>
        <span>■ IMPRIMANTE</span>
        <span>■ QR</span>
      </div>
    </div>
  );
}

Object.assign(window, {
  fk, FkButton, FkBadge, FkProductImage, FkPlusBtn, FkQty,
  FkProgress, FkWizardFooter, FkWizardHeader, FkKioskFrame,
});
