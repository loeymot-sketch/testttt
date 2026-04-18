// kiosk-ds/screens.jsx
// 6 écrans clés du parcours Kiosk V1 — rendus au format 1080×1920 portrait
// Props communs : { dir: 'ltr'|'rtl', pmr: boolean }

// ==========================================================================
// 1) ScreenIdle — Veille
// ==========================================================================
function ScreenIdle({ dir = "ltr", pmr = false }) {
  const fr = dir !== "rtl";
  return (
    <div style={{ width: "100%", height: "100%", position: "relative", background: "linear-gradient(180deg, var(--fk-red) 0%, var(--fk-red-dark) 100%)", color: "#fff" }}>
      {/* Header logo (zone haute non-PMR) */}
      <div className="fk-pmr-top" style={{
        padding: "60px 60px 0",
        display: "flex", justifyContent: "space-between", alignItems: "flex-start",
        flexDirection: dir === "rtl" ? "row-reverse" : "row",
      }}>
        <div style={{
          fontSize: 42, fontWeight: 900, letterSpacing: 3,
          textShadow: "0 2px 8px rgba(0,0,0,0.15)",
        }}>FOODKING</div>
        <div style={{ display: "flex", gap: 12 }}>
          <KPill icon="FR" label="" compact active />
          <KPill icon="EN" label="" compact />
          <KPill icon="AR" label="" compact />
        </div>
      </div>

      {/* Titre principal */}
      <div style={{
        padding: "120px 80px 0",
        textAlign: dir === "rtl" ? "right" : "left",
      }}>
        <div style={{
          fontSize: 88, fontWeight: 900, lineHeight: 1.05,
          textShadow: "0 4px 12px rgba(0,0,0,0.15)",
        }}>
          {fr ? "Bienvenue" : "مرحباً"}<br/>
          {fr ? "chez FoodKing." : "في FoodKing."}
        </div>
        <div style={{
          fontSize: 32, fontWeight: 500, marginTop: 24, opacity: 0.95,
          lineHeight: 1.35,
        }}>
          {fr ? "Commandez en quelques secondes." : "اطلب خلال ثوانٍ."}<br/>
          {fr ? "Retirez votre commande au comptoir." : "استلم طلبك من الكاونتر."}
        </div>
      </div>

      {/* Burger hero (emoji) */}
      <div style={{
        position: "absolute", top: 680, left: 0, right: 0,
        textAlign: "center", fontSize: 340,
        filter: "drop-shadow(0 20px 40px rgba(0,0,0,0.25))",
      }}>🍔</div>

      {/* CTA principal */}
      <div style={{
        position: "absolute", bottom: 260, left: 60, right: 60,
        background: "#fff", borderRadius: 36,
        padding: "40px 32px",
        boxShadow: "0 12px 32px rgba(0,0,0,0.2)",
        textAlign: "center",
      }}>
        <div style={{
          fontSize: 22, fontWeight: 700, color: "var(--fk-text-mute)",
          letterSpacing: 1.5, textTransform: "uppercase",
        }}>{fr ? "▼ touchez l'écran ▼" : "▼ المس الشاشة ▼"}</div>
        <div style={{
          fontSize: 54, fontWeight: 900, color: "var(--fk-red)",
          marginTop: 8, letterSpacing: 1,
        }}>{fr ? "COMMANDER ICI" : "اطلب هنا"}</div>
      </div>

      {/* Bouton audio description */}
      <div style={{
        position: "absolute", bottom: 120,
        [dir === "rtl" ? "right" : "left"]: 60,
      }}>
        <KAudioBtn />
      </div>

      {/* Boutons a11y (PMR + contraste) */}
      <div style={{
        position: "absolute", bottom: 120,
        [dir === "rtl" ? "left" : "right"]: 60,
        display: "flex", gap: 12,
      }}>
        <KA11yBtn icon="♿" label={fr ? "PMR" : "الإعاقة"} active={pmr} />
        <KA11yBtn icon="👁" label={fr ? "Contraste" : "التباين"} />
      </div>
    </div>
  );
}

// ==========================================================================
// 2) ScreenMenu
// ==========================================================================
function ScreenMenu({ dir = "ltr", pmr = false, customerAllergens = [] }) {
  const fr = dir !== "rtl";
  const L = (frTxt, arTxt) => fr ? frTxt : arTxt;

  const categories = [
    { emoji: "🌯", fr: "Tacos",      ar: "تاكو",       active: true, count: 4 },
    { emoji: "🍔", fr: "Burgers",    ar: "برغر",       count: 6 },
    { emoji: "🥙", fr: "Sandwichs",  ar: "ساندويش",     count: 8 },
    { emoji: "🍽️", fr: "Assiettes",   ar: "أطباق",      count: 5 },
    { emoji: "🍟", fr: "Snacking",    ar: "وجبات خفيفة", count: 7 },
    { emoji: "🥗", fr: "Salades",     ar: "سلطات",      count: 3 },
    { emoji: "🍹", fr: "Boissons",    ar: "مشروبات",    count: 12 },
    { emoji: "🍰", fr: "Desserts",    ar: "حلويات",     count: 5 },
  ];

  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{ width: "100%", height: "100%", display: "grid",
      gridTemplateColumns: dir === "rtl" ? "1fr 320px" : "320px 1fr",
      background: "var(--fk-bg)" }}>

      {/* Sidebar catégories */}
      <div style={{
        background: "var(--fk-surface)",
        [dir === "rtl" ? "borderLeft" : "borderRight"]: "1px solid var(--fk-border)",
        overflowY: "hidden", padding: "22px 0 140px",
        gridColumn: dir === "rtl" ? 2 : 1,
      }}>
        <div className="fk-pmr-top" style={{
          padding: "14px 22px 24px", textAlign: "center",
          borderBottom: "1px solid var(--fk-border)", marginBottom: 12,
        }}>
          <div style={{ fontSize: 36, fontWeight: 900, color: "var(--fk-red)", letterSpacing: 2 }}>
            {fr ? "FOODKING" : "FOODKING"}
          </div>
          <div style={{ height: 4, width: 50, background: "var(--fk-red)", borderRadius: 2, margin: "8px auto 0" }} />
        </div>
        {categories.map((c, i) => (
          <KCategoryItem key={i}
            emoji={c.emoji}
            label={L(c.fr, c.ar)}
            active={c.active}
            count={c.count}
          />
        ))}
      </div>

      {/* Zone droite */}
      <div style={{
        display: "flex", flexDirection: "column", minWidth: 0, position: "relative",
        gridColumn: dir === "rtl" ? 1 : 2,
      }}>
        {/* Header */}
        <KHeader
          title={L("Tacos", "تاكو")}
          subtitle={L("Nos spécialités", "تخصصاتنا")}
          lang={fr ? "FR" : "AR"}
          dir={dir}
        />

        {/* Filtres chips */}
        <div className="fk-pmr-top" style={{
          padding: "18px 40px 10px",
          display: "flex", gap: 12, overflowX: "auto",
          flexDirection: rowRev,
        }}>
          <KFilterChip active>{L("Tout", "الكل")}</KFilterChip>
          <KFilterChip>🌱 {L("Végé", "نباتي")}</KFilterChip>
          <KFilterChip>🕌 {L("Halal", "حلال")}</KFilterChip>
          <KFilterChip>🌶️ {L("Épicé", "حار")}</KFilterChip>
          <KFilterChip>🌾 {L("Sans gluten", "بدون غلوتين")}</KFilterChip>
          <KFilterChip>€ {L("< 10 €", "< 10 €")}</KFilterChip>
        </div>

        {/* Recherche + audio */}
        <div className="fk-pmr-top" style={{
          padding: "10px 40px 18px",
          display: "flex", gap: 14, alignItems: "center",
          flexDirection: rowRev,
        }}>
          <div style={{
            flex: 1, background: "var(--fk-surface)",
            border: "2px solid var(--fk-border)",
            borderRadius: 999,
            padding: "18px 26px",
            fontSize: 22, color: "var(--fk-text-mute)",
            display: "flex", alignItems: "center", gap: 12,
            flexDirection: rowRev,
          }}>
            <span style={{ fontSize: 28 }}>🔍</span>
            <span>{L("Rechercher un produit…", "ابحث عن منتج…")}</span>
          </div>
          <KAudioBtn small />
        </div>

        {/* Sous-catégories (exemple Sandwich → Chaud/Froid/Wrap remplacé pour Tacos) */}
        <div style={{
          padding: "6px 40px 14px",
          display: "flex", gap: 10,
          flexDirection: rowRev,
        }}>
          <KSubCategory active>{L("Tous", "الكل")}</KSubCategory>
          <KSubCategory>{L("Classiques", "كلاسيكي")}</KSubCategory>
          <KSubCategory>{L("Signature", "خاصة")}</KSubCategory>
        </div>

        {/* Rail chef pick */}
        <div style={{
          margin: "0 40px 20px",
          background: "linear-gradient(90deg, #FFF4E6 0%, #FFE8D0 100%)",
          borderRadius: 20,
          padding: "16px 20px",
          display: "flex", alignItems: "center", gap: 16,
          flexDirection: rowRev,
        }}>
          <div style={{ fontSize: 40 }}>👨‍🍳</div>
          <div style={{ flex: 1, textAlign: dir === "rtl" ? "right" : "left" }}>
            <div style={{
              fontSize: 14, fontWeight: 900, color: "var(--fk-badge-chef)",
              textTransform: "uppercase", letterSpacing: 1.5,
            }}>{L("Coup de cœur du chef", "اختيار الشيف")}</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: "var(--fk-text)" }}>
              {L("Tacos Signature XXL", "تاكو سيغنتشر XXL")}
            </div>
          </div>
          <div style={{
            background: "var(--fk-red)", color: "#fff",
            borderRadius: 999, padding: "10px 18px",
            fontSize: 16, fontWeight: 800,
          }}>12,90 €</div>
        </div>

        {/* Grille produits */}
        <div style={{ flex: 1, overflow: "hidden", padding: "4px 40px 200px" }}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 28 }}>
            <KProductCard
              name={L("Tacos M", "تاكو M")}
              desc={L("1 viande au choix, sauce, salade", "لحمة واحدة، صوص، سلطة")}
              price="6,90 €" emoji="🌯"
              badges={["halal"]}
            />
            <KProductCard
              name={L("Tacos L", "تاكو L")}
              desc={L("2 viandes au choix, sauce, salade", "لحمتان، صوص، سلطة")}
              price="8,90 €" emoji="🌯"
              badges={["halal", "spicy"]}
              chefPick
            />
            <KProductCard
              name={L("Tacos XL", "تاكو XL")}
              desc={L("3 viandes au choix, fromage fondu", "3 لحمات، جبنة ذائبة")}
              price="10,90 €" emoji="🌯"
              badges={["halal", "new"]}
            />
            <KProductCard
              name={L("Tacos XXL", "تاكو XXL")}
              desc={L("4 viandes, pour les gros appétits", "4 لحمات للشهية الكبيرة")}
              price="12,90 €" emoji="🌯"
              badges={["halal"]}
              unavailable
            />
          </div>
        </div>

        {/* Footer sticky panier */}
        <div style={{
          position: "absolute", bottom: 0, left: 0, right: 0,
          background: "#fff", borderTop: "1px solid var(--fk-border)",
          padding: "20px 32px 24px",
          boxShadow: "var(--fk-shadow-sticky)",
        }}>
          <div style={{
            display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 14,
            flexDirection: rowRev,
          }}>
            <div style={{ display: "flex", alignItems: "center", gap: 14, flexDirection: rowRev }}>
              <div style={{
                width: 72, height: 72, borderRadius: "50%",
                background: "var(--fk-surface-alt)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 34, position: "relative",
              }}>
                🛒
                <div style={{
                  position: "absolute", top: -6, right: -6,
                  background: "var(--fk-red)", color: "#fff",
                  fontSize: 18, fontWeight: 900,
                  width: 34, height: 34, borderRadius: "50%",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  border: "3px solid #fff",
                }}>2</div>
              </div>
              <div style={{ textAlign: dir === "rtl" ? "right" : "left" }}>
                <div style={{
                  fontSize: 16, color: "var(--fk-text-soft)",
                  textTransform: "uppercase", fontWeight: 700, letterSpacing: 1,
                }}>{L("Panier", "السلة")}</div>
                <div style={{ fontSize: 22, fontWeight: 700 }}>
                  {L("2 articles", "صنفان")}
                </div>
              </div>
            </div>
            <div style={{ textAlign: dir === "rtl" ? "left" : "right" }}>
              <div style={{
                fontSize: 16, color: "var(--fk-text-soft)",
                textTransform: "uppercase", fontWeight: 700, letterSpacing: 1,
              }}>{L("Total", "المجموع")}</div>
              <div style={{ fontSize: 40, fontWeight: 900, color: "var(--fk-red)" }}>14,40 €</div>
            </div>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 2fr", gap: 14 }}>
            <KGhostBtn>{L("Abandonner", "إلغاء")}</KGhostBtn>
            <KPrimaryBtn>{L("Payer — 14,40 €", "ادفع — 14,40 €")}</KPrimaryBtn>
          </div>
        </div>
      </div>
    </div>
  );
}

function KFilterChip({ children, active = false }) {
  return (
    <div style={{
      background: active ? "var(--fk-red)" : "var(--fk-surface)",
      color: active ? "#fff" : "var(--fk-text)",
      border: `2px solid ${active ? "var(--fk-red)" : "var(--fk-border-strong)"}`,
      borderRadius: 999,
      padding: "12px 22px",
      fontSize: 20, fontWeight: 800,
      whiteSpace: "nowrap",
      minHeight: 48,
    }}>{children}</div>
  );
}

function KSubCategory({ children, active = false }) {
  return (
    <div style={{
      background: active ? "var(--fk-text)" : "transparent",
      color: active ? "#fff" : "var(--fk-text-soft)",
      borderRadius: 14,
      padding: "10px 18px",
      fontSize: 18, fontWeight: 800,
      borderBottom: active ? "3px solid var(--fk-red)" : "3px solid transparent",
    }}>{children}</div>
  );
}

Object.assign(window, { ScreenIdle, ScreenMenu, KFilterChip, KSubCategory });
