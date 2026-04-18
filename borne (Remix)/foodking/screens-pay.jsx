/* ==========================================================================
   FoodKing Kiosk — Écrans de paiement, confirmation, overlays, admin
   ========================================================================== */

// ========== Écran 7 — Upsell =============================================
function ScreenUpsell({ cart, onSkip, onAdd, onBack }) {
  const [added, setAdded] = React.useState({}); // id -> count
  const upsellCount = Object.values(added).reduce((a, b) => a + b, 0);
  const handleAdd = (it) => {
    setAdded(a => ({ ...a, [it.id]: (a[it.id] || 0) + 1 }));
    onAdd(it);
  };
  return (
    <div style={{ height: "100%", background: fk.bg, display: "flex", flexDirection: "column" }}>
      {/* Header */}
      <div style={{ background: "#fff", borderBottom: `1px solid ${fk.border}`, padding: "22px 28px", display: "flex", alignItems: "center", gap: 16 }}>
        <button onClick={onBack} style={{
          width: 64, height: 64, borderRadius: "50%",
          background: "#fff", border: `2px solid ${fk.border}`,
          fontSize: 28, cursor: "pointer", color: fk.textSoft,
        }}>‹</button>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 14, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 1.5 }}>Dernière chance</div>
          <div style={{ fontSize: 34, fontWeight: 900, textTransform: "uppercase" }}>Et avec ça ?</div>
        </div>
        {upsellCount > 0 && (
          <div style={{
            background: fk.redSoft, color: fk.red,
            padding: "8px 16px", borderRadius: 999,
            fontSize: 15, fontWeight: 800,
          }}>+{upsellCount} ajouté{upsellCount > 1 ? "s" : ""}</div>
        )}
      </div>
      <div style={{ flex: 1, overflowY: "auto", padding: "22px 28px 200px" }}>
        <div style={{ fontSize: 18, color: fk.textSoft, marginBottom: 22, textAlign: "center", padding: "0 20px" }}>
          Complétez votre repas avec l'un de nos favoris
        </div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
          {UPSELL_ITEMS.map(it => {
            const count = added[it.id] || 0;
            return (
              <div key={it.id} style={{
                background: "#fff", borderRadius: 22, padding: 16,
                boxShadow: fk.shadowCard, textAlign: "center",
                border: count > 0 ? `2px solid ${fk.red}` : "2px solid transparent",
                position: "relative",
              }}>
                {count > 0 && (
                  <div style={{
                    position: "absolute", top: 10, left: 10,
                    background: fk.red, color: "#fff",
                    borderRadius: 999, padding: "4px 12px",
                    fontSize: 14, fontWeight: 800,
                  }}>×{count}</div>
                )}
                <FkProductImage emoji={it.emoji} size={180} style={{ margin: "0 auto" }} />
                <div style={{ fontSize: 20, fontWeight: 800, marginTop: 10, textTransform: "uppercase" }}>{it.name}</div>
                <div style={{ fontSize: 22, fontWeight: 800, color: fk.red, marginTop: 4, marginBottom: 10 }}>
                  {it.price.toFixed(2).replace(".", ",")} €
                </div>
                <FkButton variant="primary" size="md" fullWidth onClick={() => handleAdd(it)}>+ Ajouter</FkButton>
              </div>
            );
          })}
        </div>
      </div>
      <div style={{ position: "absolute", bottom: 0, left: 0, right: 0, background: "#fff", padding: "18px 26px 26px", borderTop: `1px solid ${fk.border}` }}>
        <FkButton variant={upsellCount > 0 ? "primary" : "secondary"} fullWidth size="lg" onClick={onSkip}>
          {upsellCount > 0 ? `Continuer vers le paiement ›` : `Non merci — Passer au paiement ›`}
        </FkButton>
      </div>
    </div>
  );
}

// ========== Écran 8 — Choix du mode de paiement ==========================
function ScreenPaymentChoice({ total, onSelect, onBack }) {
  const [method, setMethod] = React.useState(null);
  const methods = [
    { id: "cb",     name: "Carte bancaire", desc: "Sans contact, Apple Pay, Google Pay", emoji: "💳" },
    { id: "cash",   name: "Espèces",         desc: "Remise au comptoir",                 emoji: "💶" },
    { id: "tr",     name: "Tickets Resto",   desc: "Swile, Edenred, Pluxee",             emoji: "🎫" },
  ];
  return (
    <div style={{ height: "100%", background: fk.bg, display: "flex", flexDirection: "column" }}>
      <div style={{ background: "#fff", borderBottom: `1px solid ${fk.border}`, padding: "22px 28px", display: "flex", alignItems: "center", gap: 16 }}>
        <button onClick={onBack} style={{
          width: 64, height: 64, borderRadius: "50%",
          background: "#fff", border: `2px solid ${fk.border}`,
          fontSize: 28, cursor: "pointer", color: fk.textSoft,
        }}>‹</button>
        <div>
          <div style={{ fontSize: 14, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 1.5 }}>Étape finale</div>
          <div style={{ fontSize: 34, fontWeight: 900, textTransform: "uppercase" }}>Paiement</div>
        </div>
      </div>
      <div style={{ flex: 1, padding: "30px 28px", overflowY: "auto" }}>
        {/* Montant */}
        <div style={{
          background: "linear-gradient(135deg, " + fk.red + ", " + fk.redDark + ")",
          color: "#fff",
          borderRadius: 28, padding: "28px 24px",
          textAlign: "center",
          boxShadow: fk.shadowCTA,
          marginBottom: 28,
        }}>
          <div style={{ fontSize: 18, fontWeight: 700, letterSpacing: 3, textTransform: "uppercase", opacity: 0.85 }}>À payer</div>
          <div style={{ fontSize: 88, fontWeight: 900, lineHeight: 1, marginTop: 6, letterSpacing: -2 }}>
            {total.toFixed(2).replace(".", ",")} €
          </div>
        </div>

        <div style={{ fontSize: 24, fontWeight: 800, marginBottom: 14, textTransform: "uppercase", letterSpacing: 0.4 }}>
          Mode de paiement
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          {methods.map(m => (
            <div key={m.id} onClick={() => setMethod(m.id)} style={{
              background: "#fff",
              border: `3px solid ${method === m.id ? fk.red : "transparent"}`,
              borderRadius: 24, padding: 20,
              display: "flex", alignItems: "center", gap: 20,
              boxShadow: fk.shadowCard, cursor: "pointer",
              position: "relative",
            }}>
              <div style={{
                width: 96, height: 96, borderRadius: "50%",
                background: fk.surfaceAlt,
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 52, flexShrink: 0,
              }}>{m.emoji}</div>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 26, fontWeight: 800, textTransform: "uppercase" }}>{m.name}</div>
                <div style={{ fontSize: 15, color: fk.textSoft, marginTop: 3 }}>{m.desc}</div>
              </div>
              {method === m.id && (
                <div style={{
                  width: 42, height: 42, borderRadius: "50%",
                  background: fk.success, color: "#fff",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontSize: 22, fontWeight: 800,
                }}>✓</div>
              )}
            </div>
          ))}
        </div>
      </div>
      <div style={{ position: "absolute", bottom: 0, left: 0, right: 0, background: "#fff", padding: "18px 26px 26px", borderTop: `1px solid ${fk.border}` }}>
        <FkButton variant="primary" fullWidth size="lg" disabled={!method} onClick={() => onSelect(method)}>
          Valider et payer ›
        </FkButton>
      </div>
    </div>
  );
}

// ========== Écran 9 — TPE overlay =========================================
function ScreenTPE({ total, onComplete, onCancel }) {
  const [state, setState] = React.useState("waiting"); // waiting | success | error
  React.useEffect(() => {
    const t = setTimeout(() => setState("success"), 3400);
    return () => clearTimeout(t);
  }, []);
  React.useEffect(() => {
    if (state === "success") {
      const t = setTimeout(onComplete, 1600);
      return () => clearTimeout(t);
    }
  }, [state]);

  return (
    <div style={{
      height: "100%",
      background: `radial-gradient(circle at 50% 40%, #1a1a1a, #000)`,
      color: "#fff",
      display: "flex", flexDirection: "column",
      alignItems: "center", justifyContent: "center",
      padding: 40, textAlign: "center",
    }}>
      {state === "waiting" && (
        <>
          {/* Pulsing rings */}
          <div style={{ position: "relative", width: 320, height: 320, marginBottom: 50 }}>
            {[0, 1, 2].map(i => (
              <div key={i} style={{
                position: "absolute", inset: 0,
                borderRadius: "50%",
                border: `4px solid ${fk.red}`,
                animation: `fkRing 2s ease-out ${i * 0.6}s infinite`,
                opacity: 0,
              }} />
            ))}
            <div style={{
              position: "absolute", inset: 0,
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: 140,
            }}>💳</div>
          </div>
          <div style={{ fontSize: 48, fontWeight: 900, lineHeight: 1.1, letterSpacing: -0.5, marginBottom: 16 }}>
            Suivez les instructions<br/>sur le terminal
          </div>
          <div style={{ fontSize: 24, opacity: 0.7, marginBottom: 40 }}>
            Insérez, approchez ou glissez votre carte
          </div>
          <div style={{
            background: "rgba(255,255,255,0.08)",
            borderRadius: 18, padding: "14px 32px",
            fontSize: 30, fontWeight: 800,
            marginBottom: 60,
          }}>
            {total.toFixed(2).replace(".", ",")} €
          </div>
          {/* Dots loader */}
          <div style={{ display: "flex", gap: 14, marginBottom: 40 }}>
            {[0, 1, 2].map(i => (
              <div key={i} style={{
                width: 16, height: 16, borderRadius: "50%",
                background: fk.red,
                animation: `fkDot 1.4s ease-in-out ${i * 0.2}s infinite`,
              }} />
            ))}
          </div>
          <button onClick={onCancel} style={{
            background: "transparent", border: "2px solid rgba(255,255,255,0.3)",
            color: "rgba(255,255,255,0.7)",
            padding: "14px 28px", borderRadius: 999,
            fontSize: 16, fontWeight: 700, letterSpacing: 0.5,
            cursor: "pointer",
          }}>Annuler</button>
        </>
      )}

      {state === "success" && (
        <>
          <div style={{
            width: 280, height: 280, borderRadius: "50%",
            background: fk.success,
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 180, color: "#fff",
            animation: "fkBounceIn 500ms ease-out",
            boxShadow: "0 20px 60px rgba(22,163,74,0.5)",
            marginBottom: 40,
          }}>✓</div>
          <div style={{ fontSize: 56, fontWeight: 900, color: fk.success }}>Paiement accepté</div>
          <div style={{ fontSize: 22, opacity: 0.7, marginTop: 12 }}>
            Merci, préparation en cours…
          </div>
        </>
      )}
    </div>
  );
}

// ========== Écran 10 — Instruction paiement espèces =======================
function ScreenCashInstruction({ orderNumber, total, onContinue }) {
  return (
    <div style={{
      height: "100%", background: fk.bg,
      display: "flex", flexDirection: "column",
      padding: "60px 40px 30px", textAlign: "center", position: "relative",
    }}>
      <div style={{
        width: 180, height: 180, borderRadius: "50%",
        background: fk.warning, color: "#fff",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 110, margin: "0 auto 30px",
        boxShadow: "0 16px 40px rgba(245,158,11,0.35)",
        animation: "fkBounceIn 500ms ease-out",
      }}>💶</div>
      <div style={{ fontSize: 38, fontWeight: 900, color: fk.text, marginBottom: 10 }}>
        Réglez au comptoir
      </div>
      <div style={{ fontSize: 18, color: fk.textSoft, marginBottom: 30, padding: "0 20px" }}>
        Présentez votre numéro à la caisse.<br/>
        Votre commande partira en cuisine après validation.
      </div>
      <div style={{
        background: "#fff", borderRadius: 32, padding: "28px 20px",
        boxShadow: fk.shadowLift, border: `3px solid ${fk.warning}`,
        marginBottom: 24,
      }}>
        <div style={{ fontSize: 20, fontWeight: 800, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 4 }}>
          Votre numéro
        </div>
        <div style={{ fontSize: 240, fontWeight: 900, color: fk.red, lineHeight: 0.9, letterSpacing: -8 }}>
          {orderNumber}
        </div>
        <div style={{ fontSize: 26, fontWeight: 800, color: fk.text, marginTop: 10 }}>
          À régler : {total.toFixed(2).replace(".", ",")} €
        </div>
      </div>
      <div style={{ flex: 1 }} />
      <FkButton variant="primary" fullWidth size="lg" onClick={onContinue}>
        J'ai compris ›
      </FkButton>
    </div>
  );
}

// ========== Écran 14 — Erreurs globales (4 variantes) =====================
function ScreenError({ type = "network", onRetry, onBack }) {
  const variants = {
    network:  { emoji: "📡", title: "Pas de connexion", desc: "La borne fonctionne en mode limité.", retry: "Réessayer" },
    catalog:  { emoji: "⚠",  title: "Catalogue indisponible", desc: "Merci de patienter quelques secondes.", retry: "Recharger" },
    pulled:   { emoji: "🚫", title: "Produit indisponible", desc: "Ce produit a été retiré. Choisissez-en un autre.", retry: "Retour au menu" },
    payFailed:{ emoji: "❌", title: "Paiement non abouti", desc: "Merci de vous adresser au comptoir.", retry: "Retour accueil" },
  };
  const v = variants[type] || variants.network;
  return (
    <div style={{
      height: "100%", background: fk.bg, position: "relative",
      display: "flex", flexDirection: "column",
      alignItems: "center", justifyContent: "center",
      padding: 60, textAlign: "center",
    }}>
      <div style={{
        width: 220, height: 220, borderRadius: "50%",
        background: "#fff", boxShadow: fk.shadowCard,
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 140, marginBottom: 40,
        border: `4px solid ${fk.error}`,
      }}>{v.emoji}</div>
      <div style={{ fontSize: 44, fontWeight: 900, color: fk.text, marginBottom: 14 }}>{v.title}</div>
      <div style={{ fontSize: 22, color: fk.textSoft, marginBottom: 50, maxWidth: 800 }}>{v.desc}</div>
      <div style={{ width: "100%", maxWidth: 640, display: "flex", flexDirection: "column", gap: 14 }}>
        <FkButton variant="primary" fullWidth size="lg" onClick={onRetry}>{v.retry}</FkButton>
        {onBack && <FkButton variant="ghost" fullWidth size="md" onClick={onBack}>Annuler</FkButton>}
      </div>
    </div>
  );
}

// ========== Écran 11 — Waiting ============================================
function ScreenWaiting({ onDone }) {
  React.useEffect(() => {
    const t = setTimeout(onDone, 1800);
    return () => clearTimeout(t);
  }, []);
  return (
    <div style={{
      height: "100%", background: fk.bg,
      display: "flex", flexDirection: "column",
      alignItems: "center", justifyContent: "center",
      textAlign: "center", padding: 40,
    }}>
      <div style={{ fontSize: 200, marginBottom: 20, animation: "fkFloat 1.4s ease-in-out infinite alternate" }}>🍳</div>
      <div style={{ fontSize: 42, fontWeight: 900, color: fk.text, marginBottom: 16 }}>
        Nous préparons votre<br/>commande…
      </div>
      <div style={{ display: "flex", gap: 14, marginTop: 24 }}>
        {[0, 1, 2].map(i => (
          <div key={i} style={{
            width: 20, height: 20, borderRadius: "50%",
            background: fk.red,
            animation: `fkDot 1.4s ease-in-out ${i * 0.2}s infinite`,
          }} />
        ))}
      </div>
    </div>
  );
}

// ========== Écran 12 — Confirmation (numéro d'appel GÉANT) ===============
function ScreenConfirmation({ orderNumber, total, onDone, dineIn = "takeaway" }) {
  const [countdown, setCountdown] = React.useState(15);
  React.useEffect(() => {
    if (countdown === 0) { onDone(); return; }
    const t = setTimeout(() => setCountdown(c => c - 1), 1000);
    return () => clearTimeout(t);
  }, [countdown]);

  return (
    <div style={{
      height: "100%",
      background: `linear-gradient(180deg, #fff 0%, ${fk.redSoft} 100%)`,
      display: "flex", flexDirection: "column",
      padding: "60px 40px 30px", textAlign: "center",
      position: "relative",
    }}>
      {/* Check */}
      <div style={{
        width: 180, height: 180, borderRadius: "50%",
        background: fk.success, color: "#fff",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 120,
        margin: "0 auto 30px",
        animation: "fkBounceIn 500ms ease-out",
        boxShadow: "0 16px 40px rgba(22,163,74,0.35)",
      }}>✓</div>

      <div style={{ fontSize: 42, fontWeight: 900, color: fk.text, marginBottom: 8 }}>
        Commande confirmée !
      </div>
      <div style={{ fontSize: 18, color: fk.textSoft, marginBottom: 36 }}>
        Retirez votre commande quand votre numéro s'affiche
      </div>

      {/* Numéro géant */}
      <div style={{
        background: "#fff", borderRadius: 40,
        padding: "30px 20px",
        boxShadow: fk.shadowLift,
        border: `4px solid ${fk.red}`,
        marginBottom: 36,
        animation: "fkFadeZoom 600ms ease-out",
      }}>
        <div style={{ fontSize: 22, fontWeight: 800, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 6 }}>
          Votre numéro
        </div>
        <div style={{
          fontSize: 320, fontWeight: 900, color: fk.red,
          lineHeight: 0.9, letterSpacing: -10,
          fontFamily: "'Inter', sans-serif",
        }}>{orderNumber}</div>
      </div>

      {/* Details */}
      <div style={{
        display: "flex", gap: 14, marginBottom: 24,
        justifyContent: "center",
      }}>
        <FkBadge color="gray" style={{ fontSize: 18, padding: "10px 20px" }}>
          {dineIn === "dinein" ? "🍽 Sur place" : "🛍 À emporter"}
        </FkBadge>
        <FkBadge color="soft" style={{ fontSize: 18, padding: "10px 20px" }}>
          💳 {total.toFixed(2).replace(".", ",")} €
        </FkBadge>
      </div>

      <div style={{
        background: "#fff", borderRadius: 18, padding: "16px 22px",
        fontSize: 18, color: fk.textSoft, fontWeight: 600,
        boxShadow: fk.shadowCard,
        display: "inline-flex", alignItems: "center", gap: 12,
        margin: "0 auto 30px",
      }}>
        <span style={{ fontSize: 26 }}>🎫</span>
        Un reçu imprimé vient de sortir
      </div>

      <div style={{ flex: 1 }} />

      <FkButton variant="secondary" fullWidth size="md" onClick={onDone}>
        Retour à l'accueil ({countdown} s)
      </FkButton>
    </div>
  );
}

// ========== Écran 13 — Inactivity overlay ================================
function InactivityOverlay({ onContinue, onRestart }) {
  const [countdown, setCountdown] = React.useState(30);
  React.useEffect(() => {
    if (countdown === 0) { onRestart(); return; }
    const t = setTimeout(() => setCountdown(c => c - 1), 1000);
    return () => clearTimeout(t);
  }, [countdown]);

  return (
    <div style={{
      position: "absolute", inset: 0, zIndex: 100,
      background: "rgba(10,10,10,0.75)",
      backdropFilter: "blur(8px)",
      display: "flex", alignItems: "center", justifyContent: "center",
      padding: 40,
    }}>
      <div style={{
        background: "#fff", borderRadius: 32,
        padding: "48px 36px", textAlign: "center",
        maxWidth: 800, boxShadow: fk.shadowLift,
        animation: "fkBounceIn 300ms ease-out",
      }}>
        <div style={{ fontSize: 140, marginBottom: 20 }}>⏱</div>
        <div style={{ fontSize: 40, fontWeight: 900, color: fk.text, marginBottom: 14 }}>
          Vous êtes toujours là ?
        </div>
        <div style={{ fontSize: 20, color: fk.textSoft, marginBottom: 36 }}>
          Votre commande sera annulée dans<br/>
          <span style={{ fontSize: 52, fontWeight: 900, color: fk.red, display: "inline-block", marginTop: 10 }}>
            {countdown} s
          </span>
        </div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr", gap: 12 }}>
          <FkButton variant="primary" size="lg" fullWidth onClick={onContinue}>
            Oui, je continue
          </FkButton>
          <FkButton variant="ghost" size="md" fullWidth onClick={onRestart}>
            Recommencer depuis le début
          </FkButton>
        </div>
      </div>
    </div>
  );
}

// ========== Écran 15 — Admin / Staff (PIN) ===============================
function AdminOverlay({ onClose }) {
  const [pin, setPin] = React.useState("");
  const [unlocked, setUnlocked] = React.useState(false);
  const CORRECT_PIN = "1234";
  const pad = (d) => {
    if (pin.length >= 4) return;
    const next = pin + d;
    setPin(next);
    if (next.length === 4) {
      setTimeout(() => {
        if (next === CORRECT_PIN) setUnlocked(true);
        else setPin("");
      }, 200);
    }
  };
  const del = () => setPin(p => p.slice(0, -1));

  return (
    <div style={{
      position: "absolute", inset: 0, zIndex: 200,
      background: "#1a1a1a", color: "#fff",
      padding: 40, display: "flex", flexDirection: "column",
    }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 30 }}>
        <div style={{ fontSize: 28, fontWeight: 900, letterSpacing: 2, textTransform: "uppercase" }}>
          🔧 Admin borne
        </div>
        <button onClick={onClose} style={{
          width: 60, height: 60, borderRadius: "50%",
          background: "rgba(255,255,255,0.08)",
          border: "none", color: "#fff",
          fontSize: 28, cursor: "pointer",
        }}>×</button>
      </div>

      {!unlocked ? (
        <div style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center" }}>
          <div style={{ fontSize: 24, marginBottom: 20, opacity: 0.7 }}>Code staff</div>
          <div style={{ display: "flex", gap: 16, marginBottom: 50 }}>
            {[0, 1, 2, 3].map(i => (
              <div key={i} style={{
                width: 70, height: 80, borderRadius: 14,
                background: "rgba(255,255,255,0.08)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 38, fontWeight: 800,
                border: pin.length === i ? `2px solid ${fk.red}` : "2px solid transparent",
              }}>{pin[i] ? "●" : ""}</div>
            ))}
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 90px)", gap: 14 }}>
            {[1,2,3,4,5,6,7,8,9].map(n => (
              <button key={n} onClick={() => pad(String(n))} style={keypadBtn}>{n}</button>
            ))}
            <button style={{ ...keypadBtn, visibility: "hidden" }}></button>
            <button onClick={() => pad("0")} style={keypadBtn}>0</button>
            <button onClick={del} style={{ ...keypadBtn, fontSize: 22 }}>⌫</button>
          </div>
          <div style={{ marginTop: 40, fontSize: 14, opacity: 0.4 }}>Hint : 1234</div>
        </div>
      ) : (
        <div style={{ flex: 1 }}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
            {[
              { icon: "⏸", label: "Mettre en pause", action: () => alert("Borne mise en pause (simulation)") },
              { icon: "🗑", label: "Vider cache menu", action: () => alert("Cache vidé (simulation)") },
              { icon: "🌐", label: "Test réseau", action: () => alert("✓ Réseau OK · 45 ms") },
              { icon: "💳", label: "Test TPE", action: () => alert("✓ TPE prêt (Ingenico)") },
              { icon: "🖨", label: "Test impression", action: () => alert("✓ Impression reçu test OK") },
              { icon: "🚪", label: "Quitter borne", action: onClose },
            ].map(a => (
              <button key={a.label} onClick={a.action} style={{
                background: "rgba(255,255,255,0.06)",
                border: "1px solid rgba(255,255,255,0.1)",
                color: "#fff", borderRadius: 18,
                padding: "24px 18px",
                fontSize: 16, fontWeight: 700,
                cursor: "pointer",
                display: "flex", flexDirection: "column", gap: 10, alignItems: "flex-start",
              }}>
                <span style={{ fontSize: 40 }}>{a.icon}</span>
                <span>{a.label}</span>
              </button>
            ))}
          </div>
          <div style={{ marginTop: 30, padding: 20, borderRadius: 14, background: "rgba(22,163,74,0.12)", border: "1px solid rgba(22,163,74,0.3)" }}>
            <div style={{ fontSize: 14, opacity: 0.7, marginBottom: 8 }}>STATUT SYSTÈME</div>
            <div style={{ display: "flex", gap: 16, fontSize: 15 }}>
              <span>● Backend OK</span>
              <span>● TPE prêt</span>
              <span>● Imprimante OK</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

const keypadBtn = {
  width: 90, height: 90, borderRadius: 20,
  background: "rgba(255,255,255,0.08)",
  border: "1px solid rgba(255,255,255,0.15)",
  color: "#fff", fontSize: 32, fontWeight: 800,
  cursor: "pointer",
};

Object.assign(window, {
  ScreenUpsell, ScreenPaymentChoice, ScreenTPE, ScreenCashInstruction, ScreenError,
  ScreenWaiting, ScreenConfirmation, InactivityOverlay, AdminOverlay,
});
