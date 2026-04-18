// kiosk-ds/atoms.jsx
// Atomes réutilisables pour les mockups du design system Kiosk V1

// ------------ Frame de mockup kiosque 1080×1920 scalé ----------------------
function MockupFrame({ children, scale = 0.33, label, variant, dir = "ltr", contrast = "normal", pmr = false }) {
  const w = 1080, h = 1920;
  const border = variant === "aaa" ? "3px solid #000" : "1px solid #222";
  return (
    <div style={{ display: "inline-block", textAlign: "center" }}>
      <div style={{
        width: w * scale, height: h * scale,
        position: "relative", overflow: "hidden",
        borderRadius: 22 * scale,
        background: "#000", padding: 16 * scale,
        boxShadow: "0 10px 28px rgba(0,0,0,0.25)",
      }}>
        <div
          className="fk-mockup"
          data-a11y-contrast={contrast}
          data-a11y-pmr={pmr ? "true" : "false"}
          dir={dir}
          style={{
            width: w, height: h,
            transform: `scale(${scale})`,
            transformOrigin: "top left",
            background: "var(--fk-bg)",
            position: "relative", overflow: "hidden",
            border,
            borderRadius: 8,
            fontFamily: dir === "rtl" ? "var(--fk-font-arabic)" : "var(--fk-font-latin)",
            color: "var(--fk-text)",
          }}>
          {children}
        </div>
      </div>
      {label && (
        <div style={{
          marginTop: 12,
          fontSize: 14, fontWeight: 700,
          color: "#E5E2DF", letterSpacing: 0.5,
          textTransform: "uppercase",
          fontFamily: "'Inter', sans-serif",
        }}>{label}</div>
      )}
      {variant && (
        <div style={{
          marginTop: 4,
          fontSize: 12, fontWeight: 600,
          color: variantColor(variant),
          fontFamily: "'Inter', sans-serif",
        }}>{variantLabel(variant)}</div>
      )}
    </div>
  );
}

function variantLabel(v) {
  return {
    normal: "NORMAL · AA 4.5:1",
    pmr: "PMR · bande 160–1760 px",
    aaa: "CONTRASTE AAA · 7:1 · ×1.3",
    fr: "FR · LTR",
    ar: "AR · RTL · Noto Arabic",
  }[v] || v;
}
function variantColor(v) {
  return {
    normal: "#9CA3AF",
    pmr: "#60A5FA",
    aaa: "#FBBF24",
    fr: "#9CA3AF",
    ar: "#34D399",
  }[v] || "#9CA3AF";
}

// ------------ Header kiosque (titre + pills) -------------------------------
function KHeader({ title, subtitle, lang = "FR", showLoyalty = true, dir = "ltr" }) {
  return (
    <div className="fk-pmr-top" style={{
      padding: "32px 40px 20px",
      background: "var(--fk-surface)",
      borderBottom: "1px solid var(--fk-border)",
      display: "flex", alignItems: "center", justifyContent: "space-between",
      flexDirection: dir === "rtl" ? "row-reverse" : "row",
    }}>
      <div style={{ textAlign: dir === "rtl" ? "right" : "left" }}>
        <div style={{
          fontSize: 22, fontWeight: 700, color: "var(--fk-text-soft)",
          textTransform: "uppercase", letterSpacing: 2,
        }}>{subtitle}</div>
        <div style={{
          fontSize: 52, fontWeight: 900, color: "var(--fk-text)",
          letterSpacing: 0.5, lineHeight: 1.1, marginTop: 6,
        }}>{title}</div>
      </div>
      <div style={{ display: "flex", gap: 14 }}>
        {showLoyalty && <KPill icon="👤" label={dir === "rtl" ? "حسابي" : "Mon compte"} />}
        <KPill icon="⚠" label={dir === "rtl" ? "الحساسية" : "Allergènes"} />
        <KPill icon="🌐" label={lang} compact />
      </div>
    </div>
  );
}

function KPill({ icon, label, compact = false, active = false }) {
  return (
    <div style={{
      background: active ? "var(--fk-red)" : "var(--fk-surface)",
      color: active ? "var(--fk-text-on-red)" : "var(--fk-red)",
      border: "3px solid var(--fk-red)",
      borderRadius: 999,
      padding: compact ? "14px 18px" : "14px 22px",
      fontSize: 20, fontWeight: 800,
      display: "inline-flex", alignItems: "center", gap: 10,
      textTransform: "uppercase", letterSpacing: 0.5,
      minHeight: 48,
    }}>
      <span style={{ fontSize: 22 }}>{icon}</span>
      <span>{label}</span>
    </div>
  );
}

// ------------ Bouton audio description (🔊) --------------------------------
function KAudioBtn({ active = false, small = false }) {
  const sz = small ? 56 : 72;
  return (
    <div style={{
      width: sz, height: sz, borderRadius: "50%",
      background: active ? "var(--fk-red)" : "var(--fk-surface)",
      color: active ? "var(--fk-text-on-red)" : "var(--fk-red)",
      border: "3px solid var(--fk-red)",
      display: "flex", alignItems: "center", justifyContent: "center",
      fontSize: sz * 0.5, fontWeight: 900,
      boxShadow: active ? "0 0 0 4px rgba(232,0,28,0.2)" : "none",
    }}>🔊</div>
  );
}

// ------------ Bouton PMR / contraste -----------------------
function KA11yBtn({ icon, label, active = false }) {
  return (
    <div style={{
      background: active ? "var(--fk-red)" : "var(--fk-surface)",
      color: active ? "var(--fk-text-on-red)" : "var(--fk-red)",
      border: "3px solid var(--fk-red)",
      borderRadius: 18,
      padding: "14px 18px",
      fontSize: 18, fontWeight: 800,
      display: "inline-flex", alignItems: "center", gap: 10,
      minHeight: 48,
    }}>
      <span style={{ fontSize: 24 }}>{icon}</span>
      <span>{label}</span>
    </div>
  );
}

// ------------ Bouton CTA principal ----------------------------------------
function KPrimaryBtn({ children, large = false, full = false, dir = "ltr" }) {
  return (
    <div style={{
      background: "var(--fk-red)",
      color: "var(--fk-text-on-red)",
      borderRadius: 28,
      padding: large ? "28px 44px" : "22px 32px",
      fontSize: large ? 32 : 26, fontWeight: 900,
      textTransform: "uppercase", letterSpacing: 1,
      display: full ? "block" : "inline-block",
      textAlign: "center", width: full ? "100%" : "auto",
      boxShadow: "0 8px 20px rgba(232,0,28,0.25)",
      minHeight: 64,
    }}>{children}</div>
  );
}

function KGhostBtn({ children, dir = "ltr" }) {
  return (
    <div style={{
      background: "var(--fk-surface)",
      color: "var(--fk-text)",
      border: "2px solid var(--fk-border-strong)",
      borderRadius: 24,
      padding: "20px 28px",
      fontSize: 22, fontWeight: 700,
      textAlign: "center", minHeight: 64,
    }}>{children}</div>
  );
}

// ------------ Badges produit ---------------------------------------------
function KBadge({ kind, label }) {
  const cfg = {
    halal:   { bg: "var(--fk-badge-hal)", icon: "🕌" },
    veg:     { bg: "var(--fk-badge-veg)", icon: "🌱" },
    spicy:   { bg: "var(--fk-badge-spy)", icon: "🌶️" },
    new:     { bg: "var(--fk-badge-new)", icon: "✨" },
    chef:    { bg: "var(--fk-badge-chef)", icon: "👨‍🍳" },
    porkfree:{ bg: "#0D7A33",              icon: "🚫🐷" },
    glutenfree:{ bg: "#B45309",            icon: "🌾" },
  }[kind] || { bg: "#666", icon: "•" };

  return (
    <div style={{
      background: cfg.bg,
      color: "#fff",
      borderRadius: 999,
      padding: "6px 14px",
      fontSize: 16, fontWeight: 800,
      display: "inline-flex", alignItems: "center", gap: 6,
      letterSpacing: 0.3,
    }}>
      <span>{cfg.icon}</span>
      <span>{label}</span>
    </div>
  );
}

// ------------ Allergène warning (rouge pulsant) ---------------------------
function KAllergyWarn({ label }) {
  return (
    <div style={{
      background: "#FEF2F2",
      border: "3px solid var(--fk-error)",
      borderRadius: 14,
      padding: "12px 18px",
      display: "inline-flex", alignItems: "center", gap: 10,
      color: "var(--fk-error)",
      fontSize: 20, fontWeight: 800,
    }}>
      <span style={{ fontSize: 28 }}>⚠</span>
      <span>{label}</span>
    </div>
  );
}

// ------------ Footer idle countdown + timeout ------------------------------
function KIdleCountdown({ seconds, label }) {
  return (
    <div style={{
      position: "absolute", bottom: 60, left: "50%",
      transform: "translateX(-50%)",
      background: "var(--fk-surface)",
      borderRadius: 24,
      padding: "16px 28px",
      border: "2px solid var(--fk-border)",
      fontSize: 18, color: "var(--fk-text-soft)",
      fontWeight: 700,
    }}>⏱ {label} · {seconds}s</div>
  );
}

// ------------ Sections / labels de sidebar --------------------------------
function KCategoryItem({ emoji, label, active = false, count }) {
  return (
    <div style={{
      padding: "16px 14px",
      margin: "6px 14px",
      borderRadius: 18,
      background: active ? "var(--fk-red-soft)" : "transparent",
      borderLeft: active ? "5px solid var(--fk-red)" : "5px solid transparent",
      display: "flex", flexDirection: "column", alignItems: "center", gap: 8,
    }}>
      <div style={{
        width: 100, height: 100, borderRadius: "50%",
        background: active ? "var(--fk-surface)" : "var(--fk-surface-alt)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 54,
        boxShadow: active ? "var(--fk-shadow-card)" : "none",
      }}>{emoji}</div>
      <div style={{
        fontSize: 17, fontWeight: 800,
        color: active ? "var(--fk-red)" : "var(--fk-text)",
        textTransform: "uppercase", letterSpacing: 0.8,
        textAlign: "center", lineHeight: 1.15,
      }}>{label}</div>
      {count != null && (
        <div style={{ fontSize: 14, color: "var(--fk-text-mute)" }}>{count}</div>
      )}
    </div>
  );
}

// ------------ Product card miniature -------------------------------------
function KProductCard({ name, desc, price, emoji, badges = [], unavailable = false, chefPick = false }) {
  return (
    <div style={{
      background: "var(--fk-surface)",
      borderRadius: 28,
      padding: 22,
      boxShadow: "var(--fk-shadow-card)",
      position: "relative", overflow: "hidden",
      opacity: unavailable ? 0.55 : 1,
      filter: unavailable ? "grayscale(0.7)" : "none",
    }}>
      {badges.includes("new") && (
        <div style={{
          position: "absolute", top: 22, right: -40,
          background: "var(--fk-red)", color: "#fff",
          transform: "rotate(40deg)",
          fontSize: 14, fontWeight: 900, letterSpacing: 2,
          padding: "6px 44px",
          zIndex: 2,
        }}>NOUVEAU</div>
      )}
      {chefPick && (
        <div style={{
          position: "absolute", top: 20, left: 20,
          background: "var(--fk-badge-chef)", color: "#fff",
          borderRadius: 999, padding: "6px 14px",
          fontSize: 16, fontWeight: 900, letterSpacing: 0.5,
          zIndex: 2,
          display: "inline-flex", alignItems: "center", gap: 6,
        }}>👨‍🍳 Coup de cœur</div>
      )}
      {unavailable && (
        <div style={{
          position: "absolute", top: "36%", left: "50%",
          transform: "translate(-50%, -50%) rotate(-8deg)",
          background: "rgba(255,255,255,0.92)",
          border: "4px solid var(--fk-error)",
          borderRadius: 12, padding: "10px 26px",
          fontSize: 24, fontWeight: 900, color: "var(--fk-error)",
          textTransform: "uppercase", letterSpacing: 2,
          zIndex: 3,
        }}>En rupture</div>
      )}
      <div style={{
        width: "100%", height: 220,
        background: "var(--fk-surface-alt)",
        borderRadius: 18,
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 100,
      }}>{emoji}</div>
      <div style={{
        position: "absolute", right: 30,
        marginTop: -34,
        width: 72, height: 72, borderRadius: "50%",
        background: "var(--fk-red)", color: "#fff",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 36, fontWeight: 900,
        boxShadow: "0 4px 14px rgba(232,0,28,0.35)",
        zIndex: 4,
      }}>+</div>
      <div style={{
        fontSize: 24, fontWeight: 800, marginTop: 20,
        textTransform: "uppercase", letterSpacing: 0.4,
        color: "var(--fk-text)",
      }}>{name}</div>
      <div style={{ fontSize: 16, color: "var(--fk-text-soft)", marginTop: 4, minHeight: 40 }}>
        {desc}
      </div>
      <div style={{ display: "flex", gap: 6, marginTop: 8, flexWrap: "wrap" }}>
        {badges.filter(b => b !== "new").map(b => (
          <KBadge key={b} kind={b} label={badgeLabel(b)} />
        ))}
      </div>
      <div style={{
        fontSize: 28, fontWeight: 900, color: "var(--fk-red)",
        marginTop: 12,
      }}>{price}</div>
    </div>
  );
}
function badgeLabel(b) {
  return { halal: "Halal", veg: "Végé", spicy: "Épicé",
           porkfree: "Sans porc", glutenfree: "Sans gluten" }[b] || b;
}

// ------------ Export window globals ---------------------------------------
Object.assign(window, {
  MockupFrame, KHeader, KPill, KAudioBtn, KA11yBtn,
  KPrimaryBtn, KGhostBtn, KBadge, KAllergyWarn,
  KIdleCountdown, KCategoryItem, KProductCard,
  badgeLabel,
});
