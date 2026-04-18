// kiosk-ds/screens-part2.jsx
// ScreenWizard + ScreenCart + ScreenPayment + ScreenWaiting

// ==========================================================================
// 3) ScreenWizard — étape viande, avec mini-recap footer + allergène warn
// ==========================================================================
function ScreenWizard({ dir = "ltr", pmr = false, customerAllergens = [] }) {
  const fr = dir !== "rtl";
  const L = (frTxt, arTxt) => fr ? frTxt : arTxt;
  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{ width: "100%", height: "100%", display: "flex", flexDirection: "column",
      background: "var(--fk-bg)" }}>

      {/* Header wizard */}
      <div className="fk-pmr-top" style={{
        padding: "30px 40px 20px",
        background: "var(--fk-surface)",
        borderBottom: "1px solid var(--fk-border)",
      }}>
        <div style={{
          display: "flex", alignItems: "center", justifyContent: "space-between",
          marginBottom: 18, flexDirection: rowRev,
        }}>
          <div style={{ display: "flex", alignItems: "center", gap: 14, flexDirection: rowRev }}>
            <div style={{ fontSize: 72 }}>🌯</div>
            <div style={{ textAlign: dir === "rtl" ? "right" : "left" }}>
              <div style={{ fontSize: 22, color: "var(--fk-text-soft)", fontWeight: 700, letterSpacing: 1.5, textTransform: "uppercase" }}>
                {L("Vous composez", "أنت تكوّن")}
              </div>
              <div style={{ fontSize: 48, fontWeight: 900, color: "var(--fk-text)" }}>
                {L("Tacos L", "تاكو L")}
              </div>
            </div>
          </div>
          <KAudioBtn />
        </div>

        {/* Progress bar avec steps */}
        <div style={{ display: "flex", alignItems: "center", gap: 6, flexDirection: rowRev }}>
          {["Size", "Pain", "Viande", "Sauce", "Garniture", "Suppl.", "Menu"].map((s, i) => {
            const done = i < 2;
            const active = i === 2;
            return (
              <React.Fragment key={i}>
                <div style={{
                  width: 58, height: 58, borderRadius: "50%",
                  background: active ? "var(--fk-red)" : done ? "var(--fk-success)" : "var(--fk-surface)",
                  border: `3px solid ${active ? "var(--fk-red)" : done ? "var(--fk-success)" : "var(--fk-border-strong)"}`,
                  color: active || done ? "#fff" : "var(--fk-text-soft)",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontSize: 22, fontWeight: 900, flexShrink: 0,
                }}>
                  {done ? "✓" : i + 1}
                </div>
                {i < 6 && (
                  <div style={{
                    flex: 1, height: 4,
                    background: done ? "var(--fk-success)" : "var(--fk-border)",
                  }} />
                )}
              </React.Fragment>
            );
          })}
        </div>
        <div style={{
          marginTop: 12, fontSize: 18, color: "var(--fk-text-soft)",
          textAlign: dir === "rtl" ? "right" : "left",
          fontWeight: 600,
        }}>
          {L("Étape 3 / 7 · ~45 s restantes", "الخطوة 3 / 7 · ~45 ثانية متبقية")}
        </div>
      </div>

      {/* Contenu étape */}
      <div style={{ flex: 1, overflow: "hidden", padding: "32px 40px 0" }}>
        <div style={{
          fontSize: 42, fontWeight: 900, marginBottom: 8,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>
          {L("Choisis tes 2 viandes", "اختر لحمتيك")}
        </div>
        <div style={{
          fontSize: 20, color: "var(--fk-text-soft)",
          marginBottom: 20,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>
          {L("2 emplacements · tu peux doubler", "مكانان · يمكنك التكرار")}
        </div>

        {/* Allergène warning si scanné */}
        {customerAllergens.length > 0 && (
          <div style={{ marginBottom: 20 }}>
            <KAllergyWarn label={L(
              "⚠ Allergène détecté dans vos choix : lait",
              "⚠ تم اكتشاف مادة مسببة للحساسية: الحليب"
            )} />
          </div>
        )}

        {/* Grille viandes 2 cols */}
        <div style={{
          display: "grid", gridTemplateColumns: "1fr 1fr", gap: 18,
        }}>
          {[
            { emoji: "🐔", fr: "Poulet", ar: "دجاج", qty: 1 },
            { emoji: "🥙", fr: "Kebab", ar: "كباب", qty: 1, selected: true },
            { emoji: "🍖", fr: "Viande hachée", ar: "لحم مفروم", qty: 0, allergen: true },
            { emoji: "🥓", fr: "Merguez", ar: "مرغاز", qty: 0, spicy: true },
            { emoji: "🥗", fr: "Falafel", ar: "فلافل", qty: 0, veg: true },
            { emoji: "🧀", fr: "Végétarien", ar: "نباتي", qty: 0, veg: true },
          ].map((m, i) => (
            <div key={i} style={{
              background: m.selected ? "var(--fk-red-soft)" : "var(--fk-surface)",
              border: m.selected ? "3px solid var(--fk-red)" : "2px solid var(--fk-border)",
              borderRadius: 24, padding: 20,
              display: "flex", alignItems: "center", gap: 16, position: "relative",
              flexDirection: rowRev,
              minHeight: 100,
            }}>
              <div style={{
                width: 76, height: 76, borderRadius: "50%",
                background: "var(--fk-surface-alt)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 44,
              }}>{m.emoji}</div>
              <div style={{ flex: 1, textAlign: dir === "rtl" ? "right" : "left" }}>
                <div style={{ fontSize: 28, fontWeight: 800 }}>{L(m.fr, m.ar)}</div>
                <div style={{ display: "flex", gap: 6, marginTop: 6, justifyContent: dir === "rtl" ? "flex-end" : "flex-start" }}>
                  {m.veg && <KBadge kind="veg" label={L("Végé", "نباتي")} />}
                  {m.spicy && <KBadge kind="spicy" label={L("Épicé", "حار")} />}
                  {m.allergen && customerAllergens.includes("lait") && (
                    <div style={{
                      background: "#FEF2F2", color: "var(--fk-error)",
                      border: "2px solid var(--fk-error)",
                      borderRadius: 999, padding: "4px 10px",
                      fontSize: 14, fontWeight: 800,
                    }}>⚠ Lait</div>
                  )}
                </div>
              </div>
              {/* Compteur qty */}
              <div style={{
                display: "flex", alignItems: "center", gap: 12,
                flexDirection: rowRev,
              }}>
                <div style={{
                  width: 48, height: 48, borderRadius: "50%",
                  background: m.qty > 0 ? "var(--fk-red)" : "var(--fk-surface-alt)",
                  color: m.qty > 0 ? "#fff" : "var(--fk-text-mute)",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontSize: 28, fontWeight: 900,
                }}>−</div>
                <div style={{ fontSize: 28, fontWeight: 900, minWidth: 30, textAlign: "center" }}>
                  {m.qty}
                </div>
                <div style={{
                  width: 48, height: 48, borderRadius: "50%",
                  background: "var(--fk-red)", color: "#fff",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontSize: 28, fontWeight: 900,
                }}>+</div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Footer : Mini-recap + Prev/Next */}
      <div style={{
        background: "var(--fk-surface)",
        borderTop: "1px solid var(--fk-border)",
        padding: "18px 40px 24px",
        boxShadow: "var(--fk-shadow-sticky)",
      }}>
        {/* Mini-recap persistant */}
        <div style={{
          fontSize: 20, color: "var(--fk-text-soft)",
          marginBottom: 14, fontWeight: 600,
          textAlign: dir === "rtl" ? "right" : "left",
        }}>
          {L("Tacos L · Poulet, Kebab", "تاكو L · دجاج، كباب")}
          <span style={{ color: "var(--fk-red)", fontWeight: 900, marginLeft: 10 }}>
            +12,40 €
          </span>
        </div>

        <div style={{
          display: "flex", alignItems: "center", justifyContent: "space-between",
          marginBottom: 14, flexDirection: rowRev,
        }}>
          <div style={{
            background: "transparent", color: "var(--fk-text-soft)",
            fontSize: 20, fontWeight: 700,
            textDecoration: "underline",
            textAlign: "center",
          }}>{L("Abandonner l'article", "إلغاء العنصر")}</div>
          <div style={{ fontSize: 30, fontWeight: 900, color: "var(--fk-text)" }}>
            {L("Total", "المجموع")}
            <span style={{ color: "var(--fk-red)", marginLeft: 12 }}>12,40 €</span>
          </div>
        </div>

        <div style={{ display: "grid", gridTemplateColumns: "1fr 2fr", gap: 14 }}>
          <KGhostBtn>← {L("Précédent", "السابق")}</KGhostBtn>
          <KPrimaryBtn>{L("Suivant", "التالي")} →</KPrimaryBtn>
        </div>
      </div>
    </div>
  );
}

// ==========================================================================
// 4) ScreenCart
// ==========================================================================
function ScreenCart({ dir = "ltr", pmr = false }) {
  const fr = dir !== "rtl";
  const L = (frTxt, arTxt) => fr ? frTxt : arTxt;
  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{ width: "100%", height: "100%", display: "flex", flexDirection: "column",
      background: "var(--fk-bg)" }}>

      {/* Header */}
      <div className="fk-pmr-top" style={{
        padding: "32px 40px 22px", background: "var(--fk-surface)",
        borderBottom: "1px solid var(--fk-border)",
        display: "flex", alignItems: "center", justifyContent: "space-between",
        flexDirection: rowRev,
      }}>
        <div style={{ display: "flex", alignItems: "center", gap: 18, flexDirection: rowRev }}>
          <div style={{
            width: 60, height: 60, borderRadius: "50%",
            background: "var(--fk-surface-alt)",
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 32, fontWeight: 900, color: "var(--fk-text)",
          }}>←</div>
          <div style={{ textAlign: dir === "rtl" ? "right" : "left" }}>
            <div style={{ fontSize: 22, color: "var(--fk-text-soft)", fontWeight: 700, letterSpacing: 1.5, textTransform: "uppercase" }}>
              {L("Votre commande", "طلبك")}
            </div>
            <div style={{ fontSize: 44, fontWeight: 900 }}>{L("Panier", "السلة")}</div>
          </div>
        </div>
        <KAudioBtn />
      </div>

      {/* Dine-in switch */}
      <div style={{ padding: "26px 40px 18px", display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <div style={{
          background: "var(--fk-surface)", border: "3px solid var(--fk-border-strong)",
          borderRadius: 22, padding: "26px 20px",
          display: "flex", alignItems: "center", gap: 14, justifyContent: "center",
          flexDirection: rowRev,
        }}>
          <div style={{ fontSize: 44 }}>🍽️</div>
          <div style={{ fontSize: 26, fontWeight: 800 }}>{L("Sur place", "في المكان")}</div>
        </div>
        <div style={{
          background: "var(--fk-red)", border: "3px solid var(--fk-red)",
          borderRadius: 22, padding: "26px 20px",
          display: "flex", alignItems: "center", gap: 14, justifyContent: "center",
          color: "#fff",
          flexDirection: rowRev,
          boxShadow: "0 6px 16px rgba(232,0,28,0.25)",
        }}>
          <div style={{ fontSize: 44 }}>🥡</div>
          <div style={{ fontSize: 26, fontWeight: 900 }}>{L("À emporter", "للاصطحاب")}</div>
        </div>
      </div>

      {/* Lignes panier */}
      <div style={{ flex: 1, overflow: "hidden", padding: "6px 40px 0" }}>
        {[
          { name: L("Tacos L", "تاكو L"), desc: L("Poulet, Kebab · Algérienne · Menu", "دجاج، كباب · جزائرية · قائمة"), price: "11,90 €", emoji: "🌯" },
          { name: L("Coca Zéro", "كوكا زيرو"), desc: L("33 cl", "33 سل"), price: "2,50 €", emoji: "🥤" },
        ].map((l, i) => (
          <div key={i} style={{
            background: "var(--fk-surface)",
            borderRadius: 22,
            padding: 22, marginBottom: 14,
            display: "flex", alignItems: "center", gap: 18,
            flexDirection: rowRev,
            boxShadow: "var(--fk-shadow-card)",
          }}>
            <div style={{
              width: 96, height: 96, borderRadius: 18,
              background: "var(--fk-surface-alt)",
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: 54,
            }}>{l.emoji}</div>
            <div style={{ flex: 1, textAlign: dir === "rtl" ? "right" : "left" }}>
              <div style={{ fontSize: 28, fontWeight: 800 }}>{l.name}</div>
              <div style={{ fontSize: 17, color: "var(--fk-text-soft)", marginTop: 4, lineHeight: 1.3 }}>
                {l.desc}
              </div>
              <div style={{ fontSize: 24, fontWeight: 900, color: "var(--fk-red)", marginTop: 6 }}>
                {l.price}
              </div>
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 10, flexDirection: rowRev }}>
              <div style={{
                width: 52, height: 52, borderRadius: "50%",
                background: "var(--fk-surface-alt)", color: "var(--fk-text)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 30, fontWeight: 900,
              }}>−</div>
              <div style={{ fontSize: 26, fontWeight: 900, minWidth: 30, textAlign: "center" }}>1</div>
              <div style={{
                width: 52, height: 52, borderRadius: "50%",
                background: "var(--fk-red)", color: "#fff",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 30, fontWeight: 900,
              }}>+</div>
            </div>
          </div>
        ))}

        {/* Upsell card (règle statique backend) */}
        <div style={{
          background: "linear-gradient(100deg, #FFF4E6 0%, #FFE8D0 100%)",
          border: "2px dashed var(--fk-badge-chef)",
          borderRadius: 22, padding: 18,
          display: "flex", alignItems: "center", gap: 14,
          flexDirection: rowRev,
          marginBottom: 18,
        }}>
          <div style={{ fontSize: 46 }}>🧀</div>
          <div style={{ flex: 1, textAlign: dir === "rtl" ? "right" : "left" }}>
            <div style={{ fontSize: 14, fontWeight: 900, color: "var(--fk-badge-chef)", textTransform: "uppercase", letterSpacing: 1.5 }}>
              {L("Suggestion", "اقتراح")}
            </div>
            <div style={{ fontSize: 22, fontWeight: 800 }}>
              {L("Des frites Cheddar avec ça ?", "بطاطس بالشيدر مع ذلك؟")}
            </div>
          </div>
          <div style={{
            background: "var(--fk-red)", color: "#fff",
            borderRadius: 14, padding: "14px 22px",
            fontSize: 22, fontWeight: 900,
          }}>+ 1 €</div>
        </div>

        {/* Actions bas liste */}
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
          <KGhostBtn>+ {L("Ajouter un article", "إضافة صنف")}</KGhostBtn>
          <KGhostBtn>🎟 {L("Code promo", "رمز ترويجي")}</KGhostBtn>
        </div>
      </div>

      {/* Footer total + continuer */}
      <div style={{
        background: "var(--fk-surface)",
        borderTop: "1px solid var(--fk-border)",
        padding: "18px 40px 24px",
        boxShadow: "var(--fk-shadow-sticky)",
      }}>
        <div style={{
          display: "flex", justifyContent: "space-between",
          fontSize: 18, color: "var(--fk-text-soft)", fontWeight: 600,
          marginBottom: 6,
          flexDirection: rowRev,
        }}>
          <div>{L("Sous-total (HT)", "المجموع الفرعي")}</div>
          <div>13,09 €</div>
        </div>
        <div style={{
          display: "flex", justifyContent: "space-between",
          fontSize: 18, color: "var(--fk-text-soft)", fontWeight: 600,
          marginBottom: 10,
          flexDirection: rowRev,
        }}>
          <div>{L("TVA 10 %", "ضريبة 10 %")}</div>
          <div>1,31 €</div>
        </div>
        <div style={{
          display: "flex", justifyContent: "space-between", alignItems: "center",
          marginBottom: 18,
          flexDirection: rowRev,
        }}>
          <div style={{ fontSize: 32, fontWeight: 900 }}>{L("Total", "المجموع")}</div>
          <div style={{ fontSize: 48, fontWeight: 900, color: "var(--fk-red)" }}>14,40 €</div>
        </div>
        <KPrimaryBtn full>{L("Continuer →", "التالي →")}</KPrimaryBtn>
      </div>
    </div>
  );
}

// ==========================================================================
// 5) ScreenPayment
// ==========================================================================
function ScreenPayment({ dir = "ltr", pmr = false }) {
  const fr = dir !== "rtl";
  const L = (frTxt, arTxt) => fr ? frTxt : arTxt;
  const rowRev = dir === "rtl" ? "row-reverse" : "row";

  return (
    <div style={{ width: "100%", height: "100%", position: "relative",
      background: "var(--fk-bg)", display: "flex", flexDirection: "column" }}>

      {/* Header simple */}
      <div className="fk-pmr-top" style={{
        padding: "32px 40px 22px", background: "var(--fk-surface)",
        borderBottom: "1px solid var(--fk-border)",
        display: "flex", alignItems: "center", justifyContent: "space-between",
        flexDirection: rowRev,
      }}>
        <div style={{ textAlign: dir === "rtl" ? "right" : "left" }}>
          <div style={{ fontSize: 22, color: "var(--fk-text-soft)", fontWeight: 700, letterSpacing: 1.5, textTransform: "uppercase" }}>
            {L("Paiement", "الدفع")}
          </div>
          <div style={{ fontSize: 44, fontWeight: 900 }}>{L("14,40 €", "14,40 €")}</div>
        </div>
        <KAudioBtn />
      </div>

      {/* Zone centrale */}
      <div style={{ flex: 1, padding: "60px 40px", display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center" }}>
        <div style={{
          fontSize: 56, fontWeight: 900, color: "var(--fk-text)", marginBottom: 12,
          textAlign: "center", lineHeight: 1.1,
        }}>
          {L("Approchez votre carte", "قرّب بطاقتك")}
        </div>
        <div style={{
          fontSize: 26, color: "var(--fk-text-soft)", marginBottom: 60,
          textAlign: "center",
        }}>
          {L("ou insérez-la dans le terminal ci-dessous", "أو أدخلها في الجهاز أدناه")}
        </div>

        {/* TPE visualization */}
        <div style={{
          position: "relative",
          width: 420, height: 420,
          display: "flex", alignItems: "center", justifyContent: "center",
          marginBottom: 40,
        }}>
          {/* Cercles concentriques animés (pulse) */}
          <div style={{
            position: "absolute", inset: 0, borderRadius: "50%",
            border: "4px solid var(--fk-red)",
            opacity: 0.3,
          }} />
          <div style={{
            position: "absolute", inset: 40, borderRadius: "50%",
            border: "4px solid var(--fk-red)",
            opacity: 0.5,
          }} />
          <div style={{
            position: "absolute", inset: 80, borderRadius: "50%",
            border: "4px solid var(--fk-red)",
            opacity: 0.8,
          }} />
          <div style={{
            width: 220, height: 220, borderRadius: "50%",
            background: "var(--fk-red)",
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 120,
            boxShadow: "0 12px 32px rgba(232,0,28,0.35)",
          }}>💳</div>
        </div>

        {/* Méthodes acceptées */}
        <div style={{ display: "flex", gap: 18, marginBottom: 40, flexDirection: rowRev }}>
          {["CB", "VISA", "MC", "AMEX", "TR", "📱"].map((m, i) => (
            <div key={i} style={{
              background: "var(--fk-surface)",
              border: "2px solid var(--fk-border)",
              borderRadius: 14, padding: "12px 18px",
              fontSize: 22, fontWeight: 900, color: "var(--fk-text)",
              minHeight: 54,
              display: "flex", alignItems: "center",
            }}>{m}</div>
          ))}
        </div>

        {/* Status */}
        <div style={{
          fontSize: 20, color: "var(--fk-text-soft)", fontWeight: 600,
          display: "flex", gap: 10, alignItems: "center",
        }}>
          <span style={{ fontSize: 22, color: "var(--fk-success)" }}>●</span>
          {L("Terminal prêt · Montant validé", "الجهاز جاهز · تم التحقق من المبلغ")}
        </div>
      </div>

      {/* Footer */}
      <div style={{ padding: "0 40px 40px" }}>
        <KGhostBtn>{L("Annuler le paiement", "إلغاء الدفع")}</KGhostBtn>
      </div>
    </div>
  );
}

// ==========================================================================
// 6) ScreenWaiting — numéro de commande
// ==========================================================================
function ScreenWaiting({ dir = "ltr", pmr = false }) {
  const fr = dir !== "rtl";
  const L = (frTxt, arTxt) => fr ? frTxt : arTxt;

  return (
    <div style={{
      width: "100%", height: "100%",
      background: "linear-gradient(180deg, var(--fk-success) 0%, #0D7A33 100%)",
      color: "#fff",
      display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center",
      padding: 60, position: "relative",
    }}>
      <div className="fk-pmr-top" style={{
        position: "absolute", top: 40, left: 40, right: 40,
        display: "flex", justifyContent: "space-between", alignItems: "flex-start",
      }}>
        <div style={{
          fontSize: 32, fontWeight: 900, letterSpacing: 2, opacity: 0.85,
        }}>FOODKING</div>
        <KAudioBtn />
      </div>

      <div style={{
        fontSize: 80, marginBottom: 20,
      }}>✓</div>
      <div style={{
        fontSize: 48, fontWeight: 800, marginBottom: 20, opacity: 0.95,
        textAlign: "center", lineHeight: 1.2,
      }}>
        {L("Commande validée !", "تم تأكيد الطلب!")}
      </div>
      <div style={{
        fontSize: 28, fontWeight: 500, marginBottom: 50, opacity: 0.9,
        textAlign: "center",
      }}>
        {L("Votre numéro est", "رقمك هو")}
      </div>

      {/* Numéro XL */}
      <div style={{
        background: "#fff", color: "var(--fk-success)",
        borderRadius: 40, padding: "40px 80px",
        marginBottom: 40,
        boxShadow: "0 16px 40px rgba(0,0,0,0.25)",
      }}>
        <div style={{ fontSize: 280, fontWeight: 900, lineHeight: 1, letterSpacing: -4 }}>42</div>
      </div>

      <div style={{
        fontSize: 30, fontWeight: 700, opacity: 0.95,
        textAlign: "center", lineHeight: 1.3,
      }}>
        {L("Retirez votre commande", "استلم طلبك")}<br/>
        {L("quand votre numéro apparaîtra", "عندما يظهر رقمك")}
      </div>

      <div style={{
        marginTop: 60,
        fontSize: 22, opacity: 0.85,
        display: "flex", alignItems: "center", gap: 12,
      }}>
        ⏱ {L("Temps estimé : ~6 min", "الوقت المتوقع: ~6 دقائق")}
      </div>

      {/* Ticket check */}
      <div style={{
        position: "absolute", bottom: 60, left: 60, right: 60,
        background: "rgba(255,255,255,0.15)",
        backdropFilter: "blur(10px)",
        borderRadius: 20,
        padding: "20px 28px",
        display: "flex", alignItems: "center", gap: 16,
        justifyContent: "center",
      }}>
        <div style={{ fontSize: 32 }}>🖨</div>
        <div style={{ fontSize: 22, fontWeight: 600 }}>
          {L("Un ticket a été imprimé", "تمت طباعة الإيصال")}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { ScreenWizard, ScreenCart, ScreenPayment, ScreenWaiting });
