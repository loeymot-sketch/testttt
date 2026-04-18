// kiosk-ds/consent.jsx
// 3 écrans de consentement RGPD + 1 overlay idle countdown
// Conformité CNIL : opt-in explicite, pré-décoché, refus équivalent visuel

// --------------------------------------------------------------------------
// Écran commun — layout consent
// --------------------------------------------------------------------------
function ConsentLayout({ icon, title, subtitle, bullets, accept, reject, footer, dir = "ltr", footerTone = "neutral" }) {
  const rowRev = dir === "rtl" ? "row-reverse" : "row";
  const fr = dir !== "rtl";
  return (
    <div style={{
      width: "100%", height: "100%",
      background: "var(--fk-bg)",
      display: "flex", flexDirection: "column",
      padding: "90px 60px 60px",
      position: "relative",
    }}>
      <div className="fk-pmr-top" style={{
        position: "absolute", top: 32, right: 32,
      }}>
        <KAudioBtn />
      </div>

      {/* Icône illustrative */}
      <div style={{
        width: 200, height: 200, borderRadius: "50%",
        background: "var(--fk-surface)",
        border: "4px solid var(--fk-red)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 110, margin: "0 auto 40px",
        boxShadow: "var(--fk-shadow-card)",
      }}>{icon}</div>

      {/* Titre */}
      <div style={{
        fontSize: 48, fontWeight: 900, color: "var(--fk-text)",
        textAlign: "center", lineHeight: 1.1, marginBottom: 18,
      }}>{title}</div>
      <div style={{
        fontSize: 24, color: "var(--fk-text-soft)", fontWeight: 500,
        textAlign: "center", lineHeight: 1.4, marginBottom: 40,
        maxWidth: 900, marginLeft: "auto", marginRight: "auto",
      }}>{subtitle}</div>

      {/* Bullets info */}
      <div style={{
        background: "var(--fk-surface)",
        borderRadius: 24, padding: 32,
        border: "1px solid var(--fk-border)",
        marginBottom: 36,
      }}>
        {bullets.map((b, i) => (
          <div key={i} style={{
            display: "flex", gap: 18, alignItems: "flex-start",
            padding: "12px 0",
            borderBottom: i < bullets.length - 1 ? "1px solid var(--fk-border)" : "none",
            flexDirection: rowRev,
          }}>
            <div style={{ fontSize: 32, flexShrink: 0 }}>{b.icon}</div>
            <div style={{ flex: 1, textAlign: dir === "rtl" ? "right" : "left" }}>
              <div style={{ fontSize: 22, fontWeight: 800, color: "var(--fk-text)" }}>{b.title}</div>
              <div style={{ fontSize: 19, color: "var(--fk-text-soft)", marginTop: 4, lineHeight: 1.35 }}>{b.desc}</div>
            </div>
          </div>
        ))}
      </div>

      {/* Boutons Accepter / Refuser — équivalents visuels (CNIL) */}
      <div style={{ marginTop: "auto" }}>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 18, marginBottom: 24 }}>
          {/* Note: RTL swap so "Accepter" stays at the "leading" side cognitive */}
          <div style={{
            background: "var(--fk-surface)", color: "var(--fk-text)",
            border: "3px solid var(--fk-border-strong)",
            borderRadius: 24, padding: "26px 22px",
            textAlign: "center",
            fontSize: 26, fontWeight: 800,
            minHeight: 90,
          }}>
            <div style={{ fontSize: 28, marginBottom: 4 }}>✕</div>
            {reject}
          </div>
          <div style={{
            background: "var(--fk-red)", color: "#fff",
            border: "3px solid var(--fk-red)",
            borderRadius: 24, padding: "26px 22px",
            textAlign: "center",
            fontSize: 26, fontWeight: 900,
            boxShadow: "0 8px 20px rgba(232,0,28,0.25)",
            minHeight: 90,
          }}>
            <div style={{ fontSize: 28, marginBottom: 4 }}>✓</div>
            {accept}
          </div>
        </div>

        {/* Footer privacy notice */}
        <div style={{
          fontSize: 16, color: "var(--fk-text-mute)",
          textAlign: "center", lineHeight: 1.45,
          padding: "16px 20px",
          background: footerTone === "legal" ? "#F5F5F5" : "transparent",
          borderRadius: 12,
        }}>
          {footer}
        </div>
      </div>
    </div>
  );
}

// --------------------------------------------------------------------------
// 1) Consent Heatmap (analytics opt-in)
// --------------------------------------------------------------------------
function ConsentHeatmap({ dir = "ltr" }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  return (
    <ConsentLayout
      dir={dir}
      icon="📊"
      title={L(
        "Aide-nous à améliorer l'expérience",
        "ساعدنا على تحسين التجربة"
      )}
      subtitle={L(
        "Nous collectons des statistiques anonymes sur votre parcours (aucune donnée personnelle).",
        "نجمع إحصائيات مجهولة عن تصفحك (بدون أي بيانات شخصية)."
      )}
      bullets={[
        {
          icon: "🔒",
          title: L("Totalement anonyme", "مجهول تماماً"),
          desc: L(
            "Aucune donnée ne permet de vous identifier. Pas de nom, email, téléphone, carte bancaire.",
            "لا توجد بيانات تسمح بالتعرف عليك. لا اسم، لا بريد، لا هاتف، لا بطاقة."
          )
        },
        {
          icon: "⏱",
          title: L("Valable cette session uniquement", "صالح لهذه الجلسة فقط"),
          desc: L(
            "Votre choix expire dès que vous quittez la borne. Il ne sera pas mémorisé.",
            "ينتهي اختيارك عند مغادرتك. لن يتم حفظه."
          )
        },
        {
          icon: "🎯",
          title: L("Objectif : améliorer le menu et le parcours", "الهدف: تحسين القائمة والتصفح"),
          desc: L(
            "Nous regardons uniquement où les clients hésitent, pas qui clique sur quoi.",
            "نلاحظ فقط أين يتردد العملاء، وليس من ينقر على ماذا."
          )
        },
      ]}
      accept={L("Accepter", "موافقة")}
      reject={L("Refuser", "رفض")}
      footer={L(
        "Conformément au RGPD et aux recommandations CNIL, votre refus n'empêche en rien l'utilisation de la borne. Données hébergées en France · Durée de conservation : 30 jours anonymisés.",
        "وفقاً للائحة الأوروبية وتوصيات CNIL، رفضك لا يمنعك من استخدام الجهاز. البيانات مخزنة في فرنسا · مدة الحفظ: 30 يوماً مجهولة."
      )}
      footerTone="legal"
    />
  );
}

// --------------------------------------------------------------------------
// 2) Consent Loyalty scan (QR fidélité)
// --------------------------------------------------------------------------
function ConsentLoyalty({ dir = "ltr" }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  return (
    <ConsentLayout
      dir={dir}
      icon="👤"
      title={L(
        "Connectez votre compte fidélité",
        "اربط حساب الولاء الخاص بك"
      )}
      subtitle={L(
        "Scannez le QR code de votre carte fidélité pour retrouver vos préférences.",
        "امسح رمز QR الخاص بك لاستعادة تفضيلاتك."
      )}
      bullets={[
        {
          icon: "🎁",
          title: L("Récupérez vos points de fidélité", "استرجع نقاط الولاء"),
          desc: L(
            "Utilisez vos points pour des réductions ou des produits offerts.",
            "استخدم نقاطك للحصول على خصومات أو منتجات مجانية."
          )
        },
        {
          icon: "⚠",
          title: L("Alerte allergènes personnalisée", "تنبيه حساسية مخصص"),
          desc: L(
            "Si vous avez déclaré des allergies, nous vous alerterons pendant la composition.",
            "إذا أعلنت عن حساسية، سننبهك خلال التكوين."
          )
        },
        {
          icon: "⚡",
          title: L("Mode Turbo : recommandez votre dernière commande", "الوضع السريع: كرّر طلبك السابق"),
          desc: L(
            "Votre dernière commande en 1 seul tap, sans repasser par le wizard.",
            "طلبك السابق بنقرة واحدة، دون المرور بالمعالج."
          )
        },
      ]}
      accept={L("Scanner mon QR", "مسح QR الخاص بي")}
      reject={L("Continuer sans", "المتابعة بدون")}
      footer={L(
        "Aucune donnée nominative n'est stockée sur la borne. Les informations sont effacées dès que vous quittez la session. Gestion de votre compte : foodking.fr/mon-compte",
        "لا يتم تخزين أي بيانات شخصية على الجهاز. يتم حذف المعلومات عند مغادرتك. إدارة حسابك: foodking.fr/mon-compte"
      )}
    />
  );
}

// --------------------------------------------------------------------------
// 3) Consent Mobile transfer (QR panier mobile — V1.5)
// --------------------------------------------------------------------------
function ConsentMobile({ dir = "ltr" }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  return (
    <div style={{ position: "relative", width: "100%", height: "100%" }}>
      <ConsentLayout
        dir={dir}
        icon="📱"
        title={L(
          "Finir ma commande sur mon téléphone",
          "إنهاء طلبي على هاتفي"
        )}
        subtitle={L(
          "Libérez la borne. Scannez le QR avec votre téléphone pour reprendre votre panier.",
          "حرّر الجهاز. امسح رمز QR بهاتفك لاستعادة سلتك."
        )}
        bullets={[
          {
            icon: "🛒",
            title: L("Votre panier transféré intact", "سلتك كما هي"),
            desc: L(
              "Tous vos choix (viandes, sauces, menus) sont conservés sur l'app mobile.",
              "كل اختياراتك (لحوم، صوصات، قوائم) تُحفظ على تطبيق الجوال."
            )
          },
          {
            icon: "⏳",
            title: L("Le QR expire dans 10 minutes", "ينتهي QR بعد 10 دقائق"),
            desc: L(
              "Passé ce délai, le panier est automatiquement supprimé.",
              "بعد هذه المدة، يتم حذف السلة تلقائياً."
            )
          },
          {
            icon: "🔐",
            title: L("Lien unique, chiffré, non partageable", "رابط فريد، مشفر، غير قابل للمشاركة"),
            desc: L(
              "Impossible pour un tiers d'accéder à votre panier via ce QR.",
              "لا يمكن لطرف ثالث الوصول إلى سلتك عبر هذا QR."
            )
          },
        ]}
        accept={L("Générer le QR", "توليد QR")}
        reject={L("Continuer sur la borne", "المتابعة على الجهاز")}
        footer={L(
          "Votre session sur la borne se termine dès la génération du QR. Aucune donnée personnelle n'est stockée.",
          "تنتهي جلستك على الجهاز فور توليد QR. لا يتم تخزين أي بيانات شخصية."
        )}
      />

      {/* Marqueur V1.5 */}
      <DeferredRibbon label="V1.5 — non intégré V1" />
    </div>
  );
}

// --------------------------------------------------------------------------
// Bonus — Idle countdown overlay
// --------------------------------------------------------------------------
function IdleCountdownOverlay({ dir = "ltr", seconds = 8 }) {
  const fr = dir !== "rtl";
  const L = (f, a) => fr ? f : a;
  return (
    <div style={{
      position: "absolute", inset: 0, zIndex: 200,
      background: "rgba(26,26,26,0.72)",
      backdropFilter: "blur(14px)",
      display: "flex", alignItems: "center", justifyContent: "center",
      padding: 80,
    }}>
      <div style={{
        background: "#fff", borderRadius: 36, padding: "64px 60px",
        textAlign: "center", maxWidth: 800,
        boxShadow: "var(--fk-shadow-modal)",
      }}>
        <div style={{
          fontSize: 120, marginBottom: 12,
        }}>⏱</div>
        <div style={{
          fontSize: 48, fontWeight: 900, color: "var(--fk-text)",
          lineHeight: 1.15, marginBottom: 16,
        }}>{L("Êtes-vous toujours là ?", "هل لا تزال هنا؟")}</div>
        <div style={{
          fontSize: 24, color: "var(--fk-text-soft)", marginBottom: 32,
          lineHeight: 1.4,
        }}>
          {L("La session va se fermer dans", "ستغلق الجلسة خلال")}
          <div style={{ fontSize: 96, fontWeight: 900, color: "var(--fk-red)", marginTop: 8, lineHeight: 1 }}>
            {seconds}s
          </div>
        </div>
        <KPrimaryBtn full large>{L("Je suis là !", "أنا هنا!")}</KPrimaryBtn>
      </div>
    </div>
  );
}

// --------------------------------------------------------------------------
// Ruban "non intégré V1"
// --------------------------------------------------------------------------
function DeferredRibbon({ label }) {
  return (
    <div style={{
      position: "absolute", top: 24, left: -80,
      background: "#111", color: "#FFB800",
      padding: "10px 100px",
      fontSize: 22, fontWeight: 900, letterSpacing: 2,
      textTransform: "uppercase",
      transform: "rotate(-22deg)",
      boxShadow: "0 4px 14px rgba(0,0,0,0.35)",
      zIndex: 100,
      border: "2px dashed #FFB800",
    }}>
      ⚠ {label}
    </div>
  );
}

Object.assign(window, {
  ConsentLayout, ConsentHeatmap, ConsentLoyalty, ConsentMobile,
  IdleCountdownOverlay, DeferredRibbon,
});
