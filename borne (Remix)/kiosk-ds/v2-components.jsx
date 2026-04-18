// kiosk-ds/v2-components.jsx
// Composants V2 — LIVRÉS au design system, NON intégrés V1
// 1) Preview image dynamique produit (wizard)
// 2) Upsell contextuel avec stats affichées

// --------------------------------------------------------------------------
// 1) WizardPreviewDynamic — l'image produit se construit pendant le wizard
// --------------------------------------------------------------------------
function WizardPreviewDynamic({ dir = "ltr" }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{ position: "relative", width: "100%", height: "100%",
      background: "var(--fk-bg)", display: "flex", flexDirection: "column" }}>

      <DeferredRibbon label="V2 — non intégré V1" />

      {/* Header wizard */}
      <div style={{
        padding: "30px 40px 20px",
        background: "var(--fk-surface)",
        borderBottom: "1px solid var(--fk-border)",
      }}>
        <div style={{
          display: "flex", alignItems: "center", justifyContent: "space-between",
          flexDirection: rowRev,
        }}>
          <div style={{ textAlign: dir === "rtl" ? "right" : "left" }}>
            <div style={{ fontSize: 20, color: "var(--fk-text-soft)", fontWeight: 700, letterSpacing: 1.5, textTransform: "uppercase" }}>
              {L("Vous composez", "أنت تكوّن")}
            </div>
            <div style={{ fontSize: 44, fontWeight: 900, color: "var(--fk-text)" }}>
              {L("Tacos L", "تاكو L")}
            </div>
          </div>
          <KAudioBtn />
        </div>
      </div>

      {/* PREVIEW PRODUIT LIVE — grosse zone centrale */}
      <div style={{
        background: "linear-gradient(180deg, #FFF4E6 0%, #FFE8D0 100%)",
        padding: "30px 40px",
        position: "relative",
        minHeight: 600,
      }}>
        <div style={{
          fontSize: 16, fontWeight: 900, color: "var(--fk-badge-chef)",
          textTransform: "uppercase", letterSpacing: 2,
          textAlign: "center", marginBottom: 12,
        }}>
          ✨ {L("Votre création en temps réel", "إبداعك في الوقت الحقيقي")}
        </div>

        {/* Image composée en couches (layers) */}
        <div style={{
          width: 540, height: 420, margin: "0 auto",
          position: "relative",
          display: "flex", alignItems: "center", justifyContent: "center",
        }}>
          {/* Chaque couche simule un SVG empilé */}
          <div style={{ position: "absolute", fontSize: 380, filter: "drop-shadow(0 20px 30px rgba(0,0,0,0.25))" }}>🌯</div>
          <div style={{
            position: "absolute", top: 110, left: 130,
            fontSize: 120, transform: "rotate(-10deg)",
          }}>🐔</div>
          <div style={{
            position: "absolute", top: 140, right: 100,
            fontSize: 110, transform: "rotate(15deg)",
          }}>🥙</div>
          <div style={{
            position: "absolute", bottom: 90, left: 180,
            fontSize: 80, transform: "rotate(-5deg)",
          }}>🧀</div>
          <div style={{
            position: "absolute", bottom: 100, right: 150,
            fontSize: 70,
          }}>🍅</div>
        </div>

        {/* Labels de couches actives */}
        <div style={{
          display: "flex", gap: 10, flexWrap: "wrap", justifyContent: "center",
          marginTop: 20,
        }}>
          {[
            { icon: "🌯", label: L("Tacos L", "تاكو L"), step: "base" },
            { icon: "🐔", label: L("Poulet", "دجاج"), step: "meat" },
            { icon: "🥙", label: L("Kebab", "كباب"), step: "meat" },
            { icon: "🧀", label: L("Fromage fondu", "جبنة ذائبة"), step: "garniture" },
            { icon: "🍅", label: L("Tomate", "طماطم"), step: "garniture" },
          ].map((l, i) => (
            <div key={i} style={{
              background: "var(--fk-surface)",
              borderRadius: 999, padding: "6px 14px",
              fontSize: 16, fontWeight: 700,
              display: "inline-flex", alignItems: "center", gap: 6,
              border: "2px solid var(--fk-border)",
            }}>
              <span>{l.icon}</span>
              <span>{l.label}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Étape actuelle */}
      <div style={{ padding: "24px 40px 16px" }}>
        <div style={{
          fontSize: 28, fontWeight: 900, marginBottom: 14,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>
          {L("Choisis ta sauce", "اختر الصوص")}
        </div>

        <div style={{
          display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 12,
        }}>
          {[
            { emoji: "🔴", fr: "Algérienne", ar: "جزائرية" },
            { emoji: "🟡", fr: "Harissa", ar: "هريسة", selected: true },
            { emoji: "🟢", fr: "Samouraï", ar: "ساموراي" },
            { emoji: "⚪", fr: "Blanche", ar: "أبيض" },
            { emoji: "🟤", fr: "BBQ", ar: "باربكيو" },
            { emoji: "🚫", fr: "Sans sauce", ar: "بدون صوص" },
          ].map((s, i) => (
            <div key={i} style={{
              background: s.selected ? "var(--fk-red-soft)" : "var(--fk-surface)",
              border: s.selected ? "3px solid var(--fk-red)" : "2px solid var(--fk-border)",
              borderRadius: 18, padding: "18px 12px",
              display: "flex", flexDirection: "column", alignItems: "center", gap: 6,
              minHeight: 110,
            }}>
              <div style={{ fontSize: 44 }}>{s.emoji}</div>
              <div style={{ fontSize: 18, fontWeight: 800, textAlign: "center" }}>
                {L(s.fr, s.ar)}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Note design system */}
      <div style={{
        margin: "0 40px 20px",
        padding: 16,
        background: "#FFF8E1",
        border: "2px solid #FBBF24",
        borderRadius: 12,
        fontSize: 14, color: "#92400E",
        lineHeight: 1.4,
      }}>
        <strong>📦 Matrice d'assets requise (V2) :</strong><br/>
        6 templates × (3 sizes) × (N viandes) × (N garnitures visibles) × (N sauces représentables) ≈ ~200 couches SVG à produire. Bibliothèque graphique à lancer avant activation V2.
      </div>

      {/* Footer */}
      <div style={{
        background: "var(--fk-surface)",
        borderTop: "1px solid var(--fk-border)",
        padding: "14px 40px 18px",
        boxShadow: "var(--fk-shadow-sticky)",
      }}>
        <div style={{
          fontSize: 18, color: "var(--fk-text-soft)",
          marginBottom: 12, fontWeight: 600,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>
          {L("Tacos L · Poulet, Kebab · Harissa", "تاكو L · دجاج، كباب · هريسة")}
          <span style={{ color: "var(--fk-red)", fontWeight: 900, marginLeft: 10 }}>
            +12,40 €
          </span>
        </div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 2fr", gap: 14 }}>
          <KGhostBtn>← {L("Précédent", "السابق")}</KGhostBtn>
          <KPrimaryBtn>{L("Suivant", "التالي")} →</KPrimaryBtn>
        </div>
      </div>
    </div>
  );
}

// --------------------------------------------------------------------------
// 2) UpsellWithStats — upsell avec social proof chiffré
// --------------------------------------------------------------------------
function UpsellWithStats({ dir = "ltr" }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{
      position: "relative",
      width: "100%", height: "100%",
      background: "rgba(26,26,26,0.6)",
      backdropFilter: "blur(8px)",
      display: "flex", alignItems: "center", justifyContent: "center",
      padding: 50,
    }}>
      <DeferredRibbon label="V2 — non intégré V1" />

      <div style={{
        background: "#fff", borderRadius: 36,
        padding: "40px 36px 28px",
        maxWidth: 900, width: "100%",
        boxShadow: "var(--fk-shadow-modal)",
        position: "relative",
      }}>
        {/* Close */}
        <div style={{
          position: "absolute", top: 20, right: 20,
          width: 50, height: 50, borderRadius: "50%",
          background: "var(--fk-surface-alt)",
          display: "flex", alignItems: "center", justifyContent: "center",
          fontSize: 28, fontWeight: 900, color: "var(--fk-text-soft)",
        }}>×</div>

        {/* Illustration */}
        <div style={{
          width: 200, height: 200, borderRadius: "50%",
          background: "linear-gradient(135deg, #FFF4E6 0%, #FFE8D0 100%)",
          margin: "10px auto 20px",
          display: "flex", alignItems: "center", justifyContent: "center",
          fontSize: 120,
          boxShadow: "0 10px 24px rgba(0,0,0,0.08)",
        }}>🧀</div>

        {/* Titre + stat */}
        <div style={{
          fontSize: 40, fontWeight: 900, textAlign: "center",
          lineHeight: 1.15, marginBottom: 10,
        }}>
          {L("Des frites Cheddar avec ça ?", "بطاطس بالشيدر مع ذلك؟")}
        </div>

        {/* STAT CHIFFRÉE — distinct V2 */}
        <div style={{
          background: "#F3E8FF",
          border: "2px solid #A855F7",
          borderRadius: 16,
          padding: "14px 20px",
          margin: "16px auto 24px",
          maxWidth: 600,
          display: "flex", alignItems: "center", gap: 14,
          flexDirection: rowRev,
          justifyContent: "center",
        }}>
          <div style={{ fontSize: 48, fontWeight: 900, color: "#7E22CE" }}>78%</div>
          <div style={{
            fontSize: 18, color: "#581C87", fontWeight: 700, lineHeight: 1.3,
            textAlign: dir === "rtl" ? "right" : "left",
          }}>
            {L(
              "des clients qui ont pris un Kebab ajoutent des frites Cheddar",
              "من العملاء الذين اختاروا كباب أضافوا بطاطس بالشيدر"
            )}
          </div>
        </div>

        {/* Description + prix */}
        <div style={{
          fontSize: 22, color: "var(--fk-text-soft)", textAlign: "center",
          marginBottom: 20, lineHeight: 1.4,
        }}>
          {L(
            "Portion généreuse · Cheddar anglais fondu · Herbes",
            "حصة سخية · شيدر إنجليزي ذائب · أعشاب"
          )}
        </div>

        <div style={{
          display: "flex", alignItems: "center", justifyContent: "center",
          gap: 16, marginBottom: 24,
          flexDirection: rowRev,
        }}>
          <div style={{
            fontSize: 22, color: "var(--fk-text-mute)",
            textDecoration: "line-through",
          }}>4,90 €</div>
          <div style={{
            fontSize: 44, fontWeight: 900, color: "var(--fk-red)",
          }}>+1,00 €</div>
        </div>

        {/* CTAs */}
        <div style={{ display: "grid", gridTemplateColumns: "1fr 2fr", gap: 14 }}>
          <KGhostBtn>{L("Non merci", "لا شكراً")}</KGhostBtn>
          <KPrimaryBtn>{L("Ajouter pour 1 €", "أضف مقابل 1 €")}</KPrimaryBtn>
        </div>

        {/* Warning V2 */}
        <div style={{
          marginTop: 20,
          fontSize: 14, color: "#92400E", textAlign: "center",
          padding: "10px 14px",
          background: "#FFF8E1", borderRadius: 10,
          border: "1px solid #FBBF24",
        }}>
          ⚠ Les statistiques temps réel sont <strong>strictement interdites en V1</strong> (brief §3). Cette variante sera activée en V2 si la décision stratégique est prise.
        </div>
      </div>
    </div>
  );
}

// --------------------------------------------------------------------------
// 3) QR finir sur mobile — V1.5 (plus détaillé)
// --------------------------------------------------------------------------
function MobileTransferQR({ dir = "ltr" }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{ position: "relative", width: "100%", height: "100%",
      background: "var(--fk-bg)", display: "flex", flexDirection: "column",
      padding: "80px 50px 50px" }}>

      <DeferredRibbon label="V1.5 — non intégré V1" />

      <div style={{ textAlign: "center", marginBottom: 30 }}>
        <div style={{ fontSize: 80 }}>📱</div>
        <div style={{
          fontSize: 44, fontWeight: 900, color: "var(--fk-text)",
          lineHeight: 1.15, marginTop: 12,
        }}>{L("Finissez sur votre mobile", "أنهِ على جوالك")}</div>
        <div style={{
          fontSize: 22, color: "var(--fk-text-soft)",
          marginTop: 10, lineHeight: 1.4, fontWeight: 500,
        }}>
          {L(
            "Scannez le QR avec l'app photo.\nVotre panier suit.",
            "امسح QR بتطبيق الكاميرا.\nسلتك معك."
          )}
        </div>
      </div>

      {/* QR block */}
      <div style={{
        background: "var(--fk-surface)",
        borderRadius: 40,
        padding: 50,
        border: "3px solid var(--fk-border)",
        margin: "0 auto 30px",
        boxShadow: "var(--fk-shadow-card)",
        textAlign: "center",
      }}>
        {/* QR visual */}
        <div style={{
          width: 360, height: 360, margin: "0 auto 20px",
          background: `url("data:image/svg+xml;utf8,${encodeURIComponent(fakeQrSvg())}")`,
          backgroundSize: "contain",
          backgroundRepeat: "no-repeat",
          backgroundPosition: "center",
          borderRadius: 20,
          border: "2px solid #000",
        }} />
        <div style={{
          fontSize: 18, color: "var(--fk-text-mute)", fontWeight: 600,
          fontFamily: "monospace", letterSpacing: 2,
        }}>fk.co/r/8K4M9P</div>
      </div>

      {/* Countdown */}
      <div style={{
        background: "#FEF3C7", border: "2px solid #F59E0B",
        borderRadius: 16, padding: "18px 24px",
        display: "flex", alignItems: "center", gap: 16,
        flexDirection: rowRev,
        marginBottom: 24, justifyContent: "center",
      }}>
        <div style={{ fontSize: 36 }}>⏳</div>
        <div style={{ fontSize: 20, fontWeight: 800, color: "#92400E",
          textAlign: dir === "rtl" ? "right" : "left", lineHeight: 1.3 }}>
          {L("Le QR expire dans", "ينتهي QR خلال")} <span style={{ color: "#DC2626", fontSize: 28 }}>9:42</span>
        </div>
      </div>

      {/* Récap panier */}
      <div style={{
        background: "var(--fk-surface)",
        borderRadius: 20, padding: 22,
        border: "1px solid var(--fk-border)",
        marginBottom: 16,
      }}>
        <div style={{
          fontSize: 14, color: "var(--fk-text-mute)", fontWeight: 800,
          textTransform: "uppercase", letterSpacing: 1.5, marginBottom: 8,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>{L("Votre panier transféré", "سلتك المنقولة")}</div>
        <div style={{
          fontSize: 20, color: "var(--fk-text)",
          textAlign: dir === "rtl" ? "right" : "left", lineHeight: 1.4,
        }}>
          <div>• {L("Tacos L · Poulet, Kebab", "تاكو L · دجاج، كباب")}</div>
          <div>• {L("Coca Zéro 33 cl", "كوكا زيرو 33 سل")}</div>
        </div>
        <div style={{
          fontSize: 28, fontWeight: 900, color: "var(--fk-red)", marginTop: 10,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>14,40 €</div>
      </div>

      <div style={{ marginTop: "auto" }}>
        <KGhostBtn>{L("Revenir sur la borne", "العودة إلى الجهاز")}</KGhostBtn>
      </div>
    </div>
  );
}

// Generate a fake QR visual (SVG blocky pattern)
function fakeQrSvg() {
  const size = 21;
  let rects = "";
  const seed = 42;
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      // Fake deterministic pattern
      const val = Math.abs(Math.sin(x * 12.9898 + y * 78.233 + seed) * 43758.5453) % 1;
      if (val > 0.55) rects += `<rect x="${x}" y="${y}" width="1" height="1" fill="black"/>`;
    }
  }
  // Corner squares
  const corner = (cx, cy) =>
    `<rect x="${cx}" y="${cy}" width="7" height="7" fill="black"/>
     <rect x="${cx+1}" y="${cy+1}" width="5" height="5" fill="white"/>
     <rect x="${cx+2}" y="${cy+2}" width="3" height="3" fill="black"/>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges">
    <rect width="${size}" height="${size}" fill="white"/>
    ${rects}
    ${corner(0, 0)}${corner(size - 7, 0)}${corner(0, size - 7)}
  </svg>`;
}

Object.assign(window, { WizardPreviewDynamic, UpsellWithStats, MobileTransferQR });
