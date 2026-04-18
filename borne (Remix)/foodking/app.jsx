/* ==========================================================================
   FoodKing Kiosk — Wizard shell + App principale
   ========================================================================== */

// ========== Wizard Shell — orchestrateur des étapes ======================
function Wizard({ product, onClose, onAddToCart }) {
  // Calcule la liste dynamique d'étapes pour ce produit
  const baseSteps = TEMPLATE_STEPS[product.template] || ["recap"];

  // Filtre conditionnel: si tacos et product.size pré-défini, on skip "size"
  // si sandwich/burger/tacos et menu != complet|boisson, on skip sub-steps
  const [selections, setSelections] = React.useState({});
  const [stepIdx, setStepIdx] = React.useState(0);
  const [qty, setQty] = React.useState(1);
  const [hint, setHint] = React.useState(null);
  const [confirmClose, setConfirmClose] = React.useState(false);

  // Détecte s'il y a des choix non triviaux
  const hasSelections = React.useMemo(() => {
    const keys = Object.keys(selections).filter(k => k !== "size" || !product.size);
    return keys.some(k => {
      const v = selections[k];
      if (Array.isArray(v)) return v.length > 0;
      return v !== undefined && v !== null && v !== "";
    });
  }, [selections, product.size]);

  const safeClose = () => {
    if (hasSelections && stepIdx > 0) setConfirmClose(true);
    else onClose();
  };

  // Construit la liste d'étapes actives (avec sub-steps conditionnelles)
  const activeSteps = React.useMemo(() => {
    const out = [];
    baseSteps.forEach(s => {
      if (s === "menu") {
        out.push("menu");
        if (selections.menu === "complet") {
          out.push("friteUp", "boisson", "sauceFrite");
        } else if (selections.menu === "frites") {
          out.push("friteUp", "sauceFrite");
        } else if (selections.menu === "boisson") {
          out.push("boisson");
        }
      } else {
        out.push(s);
      }
    });
    return out;
  }, [baseSteps, selections.menu]);

  const currentStep = activeSteps[stepIdx];

  // Step icons
  const stepIcons = {
    size: "📏", pain: "🥖", meat: "🥩", sauce: "🥫",
    garniture: "🥬", supplement: "🧀", menu: "🍟",
    friteUp: "🍟", boisson: "🥤", sauceFrite: "🥫",
    recap: "📋",
  };
  const stepConfig = activeSteps.map(s => ({ id: s, label: STEP_LABELS[s], icon: stepIcons[s] }));

  // Calcul prix courant
  const currentPrice = React.useMemo(() => {
    let p = product.price;
    if (selections.size) {
      const s = SIZES.find(x => x.id === selections.size);
      if (s) p += s.delta;
    }
    if (selections.sauce?.length > 1) {
      p += (selections.sauce.length - 1) * SAUCE_EXTRA_PRICE;
    }
    // BUG FIX : sauce frites suivait la même règle mais n'était pas comptée
    if (selections.sauceFrite?.length > 1) {
      p += (selections.sauceFrite.length - 1) * SAUCE_EXTRA_PRICE;
    }
    if (selections.supplement?.length) {
      p += selections.supplement.reduce((a, id) => a + (SUPPLEMENTS.find(s => s.id === id)?.price || 0), 0);
    }
    // Suppléments viande (id = "suppViande:poulet" etc) — prix du supplément + nom de la viande
    if (selections.supplementViande?.length) {
      p += selections.supplementViande.reduce((a, v) => {
        const supp = SUPPLEMENTS.find(s => s.id === v.suppId);
        return a + (supp?.price || 0);
      }, 0);
    }
    if (selections.menu) {
      const m = MENU_CHOICES.find(x => x.id === selections.menu);
      if (m) p += m.delta;
    }
    if (selections.friteUp) {
      const f = FRITES_UPGRADE.find(x => x.id === selections.friteUp);
      if (f) p += f.delta;
    }
    return p;
  }, [product.price, selections]);

  // Validation de l'étape courante
  const validateCurrent = () => {
    switch (currentStep) {
      case "size":  return !!selections.size || "Choisissez une taille";
      case "pain":  return !!selections.pain || "Choisissez un pain";
      case "meat":  {
        const total = (selections.meat || []).reduce((a, b) => a + b.qty, 0);
        return total >= (product.meats || 1) || `Choisissez ${product.meats} viande${product.meats > 1 ? "s" : ""}`;
      }
      case "sauce":      return (Array.isArray(selections.sauce) && selections.sauce.length > 0) || "Choisissez une sauce (ou Sans sauce)";
      case "garniture":  return true;
      case "supplement": return true;
      case "menu":       return !!selections.menu || "Choisissez votre menu";
      case "friteUp":    return !!selections.friteUp || "Choisissez vos frites";
      case "boisson":    return !!selections.boisson || "Choisissez une boisson";
      case "sauceFrite": return (Array.isArray(selections.sauceFrite) && selections.sauceFrite.length > 0) || "Choisissez une sauce";
      case "recap":      return true;
      default: return true;
    }
  };

  const handleNext = () => {
    const v = validateCurrent();
    if (v !== true) {
      setHint(typeof v === "string" ? v : "Veuillez compléter l'étape");
      return;
    }
    setHint(null);
    if (stepIdx < activeSteps.length - 1) {
      setStepIdx(stepIdx + 1);
    } else {
      // Ajouter au panier
      const optionsText = buildOptionsText(selections);
      onAddToCart({
        name: product.name,
        emoji: product.emoji,
        price: currentPrice,
        qty,
        options: optionsText,
      });
    }
  };

  const handlePrev = () => {
    setHint(null);
    if (stepIdx > 0) setStepIdx(stepIdx - 1);
    else safeClose();
  };

  // Pré-remplit la taille si le produit a une taille fixe (tacos M/L/XL/XXL)
  React.useEffect(() => {
    if (product.size && !selections.size) {
      setSelections(s => ({ ...s, size: product.size }));
    }
  }, []);

  // Initialize sauce as empty array so "sans sauce" est valide
  const setPartial = (key, val) => setSelections(s => ({ ...s, [key]: val }));

  const isLast = stepIdx === activeSteps.length - 1;

  return (
    <div style={{
      position: "absolute", inset: 0, zIndex: 50,
      background: fk.bg,
      display: "flex", flexDirection: "column",
      animation: "fkFadeZoom 240ms ease-out",
    }}>
      <FkWizardHeader title={product.name} onClose={safeClose} />
      <FkProgress steps={stepConfig} current={stepIdx} />

      {/* Body scrollable */}
      <div style={{ flex: 1, overflowY: "auto", paddingBottom: 280 }}>
        {currentStep === "size" && <StepSize product={product} value={selections.size} onChange={v => setPartial("size", v)} />}
        {currentStep === "pain" && <StepPain value={selections.pain} onChange={v => setPartial("pain", v)} />}
        {currentStep === "meat" && <StepViande product={product} value={selections.meat || []} onChange={v => setPartial("meat", v)} />}
        {currentStep === "sauce" && <StepSauce value={selections.sauce || []} onChange={v => setPartial("sauce", v)} />}
        {currentStep === "garniture" && <StepGarniture value={selections.garniture || []} onChange={v => setPartial("garniture", v)} />}
        {currentStep === "supplement" && (
          <StepSupplement
            value={selections.supplement || []}
            onChange={v => setPartial("supplement", v)}
            viandeValue={selections.supplementViande || []}
            onViandeChange={v => setPartial("supplementViande", v)}
          />
        )}
        {currentStep === "menu" && <StepMenu value={selections.menu} onChange={v => setPartial("menu", v)} hideBoissonSeule={["sandwich","burger","tacos"].includes(product.template)} />}
        {currentStep === "friteUp" && <StepFriteUpgrade value={selections.friteUp} onChange={v => setPartial("friteUp", v)} />}
        {currentStep === "boisson" && <StepBoisson value={selections.boisson} onChange={v => setPartial("boisson", v)} />}
        {currentStep === "sauceFrite" && <StepSauceFrite value={selections.sauceFrite || []} onChange={v => setPartial("sauceFrite", v)} />}
        {currentStep === "recap" && <StepRecap product={product} selections={selections} totalPrice={currentPrice * qty} qty={qty} onQty={setQty} />}
      </div>

      <FkWizardFooter
        canNext={true}
        onPrev={handlePrev}
        onNext={handleNext}
        onAbandon={safeClose}
        total={currentPrice * qty}
        nextLabel={isLast ? "Ajouter au panier" : "Suivant"}
        hint={hint}
      />

      {/* Confirmation abandon wizard */}
      {confirmClose && (
        <div style={{
          position: "absolute", inset: 0, zIndex: 60,
          background: "rgba(0,0,0,0.55)", backdropFilter: "blur(6px)",
          display: "flex", alignItems: "center", justifyContent: "center", padding: 40,
        }}>
          <div style={{
            background: "#fff", borderRadius: 28,
            padding: "36px 30px", textAlign: "center",
            maxWidth: 520, boxShadow: fk.shadowLift,
            animation: "fkBounceIn 260ms ease-out",
          }}>
            <div style={{ fontSize: 80, marginBottom: 14 }}>⚠</div>
            <div style={{ fontSize: 28, fontWeight: 900, color: fk.text, marginBottom: 10 }}>
              Abandonner cet article ?
            </div>
            <div style={{ fontSize: 16, color: fk.textSoft, marginBottom: 28 }}>
              Vos choix seront perdus.
            </div>
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
              <FkButton variant="secondary" size="md" onClick={() => setConfirmClose(false)}>Continuer</FkButton>
              <FkButton variant="danger" size="md" onClick={() => { setConfirmClose(false); onClose(); }}>Abandonner</FkButton>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function buildOptionsText(sel) {
  const parts = [];
  if (sel.size) parts.push("Taille " + sel.size);
  if (sel.pain) parts.push(PAINS.find(p => p.id === sel.pain)?.name);
  if (sel.meat?.length) {
    const ms = sel.meat.map(m => {
      const v = VIANDES.find(x => x.id === m.id);
      return v?.name + (m.qty > 1 ? ` ×${m.qty}` : "");
    });
    parts.push(ms.join(", "));
  }
  if (sel.sauce?.length) {
    if (sel.sauce.length === 1 && sel.sauce[0] === "__NOSAUCE__") {
      parts.push("Sans sauce");
    } else {
      const s = sel.sauce.filter(x => x !== "__NOSAUCE__").map(id => SAUCES.find(x => x.id === id)?.name);
      if (s.length) parts.push("Sauce " + s.join(", "));
    }
  }
  if (sel.menu && sel.menu !== "aucun") {
    parts.push(MENU_CHOICES.find(m => m.id === sel.menu)?.name);
  }
  return parts.filter(Boolean).join(" · ");
}

// ==========================================================================
// APP — gère la machine à états des écrans
// ==========================================================================
function App() {
  const [tweaks, setTweaks] = React.useState(window.TWEAK_DEFAULTS || {});
  const [screen, setScreen] = React.useState("idle"); // idle|menu|wizard|cart|upsell|payment|tpe|waiting|confirm
  const [cart, setCart] = React.useState([]);
  const [dineIn, setDineIn] = React.useState("takeaway");
  const [wizardProduct, setWizardProduct] = React.useState(null);
  const [orderNumber, setOrderNumber] = React.useState(null);
  const [showInactivity, setShowInactivity] = React.useState(false);
  const [showAdmin, setShowAdmin] = React.useState(false);

  // Tweak mode protocol
  React.useEffect(() => {
    const handler = (e) => {
      if (e.data?.type === "__activate_edit_mode") setTweaks(t => ({ ...t, __editing: true }));
      if (e.data?.type === "__deactivate_edit_mode") setTweaks(t => ({ ...t, __editing: false }));
    };
    window.addEventListener("message", handler);
    window.parent.postMessage({ type: "__edit_mode_available" }, "*");
    return () => window.removeEventListener("message", handler);
  }, []);

  const updateTweak = (key, value) => {
    const next = { ...tweaks, [key]: value };
    setTweaks(next);
    window.parent.postMessage({ type: "__edit_mode_set_keys", edits: { [key]: value } }, "*");
  };

  // Total panier
  const cartTotal = cart.reduce((a, b) => a + b.price * b.qty, 0);

  const addToCart = (item) => {
    setCart(c => [...c, item]);
    setScreen("menu");
    setWizardProduct(null);
  };

  const resetOrder = () => {
    setCart([]);
    setDineIn("takeaway");
    setScreen("idle");
    setWizardProduct(null);
    setOrderNumber(null);
  };

  // Real inactivity detector — démarre après l'idle, 120s sans interaction
  React.useEffect(() => {
    if (screen === "idle" || screen === "confirm" || screen === "waiting" || screen === "tpe") return;
    let timer;
    const reset = () => {
      clearTimeout(timer);
      timer = setTimeout(() => setShowInactivity(true), 120000);
    };
    reset();
    const events = ["touchstart", "mousedown", "keydown"];
    events.forEach(e => window.addEventListener(e, reset, { passive: true }));
    return () => {
      clearTimeout(timer);
      events.forEach(e => window.removeEventListener(e, reset));
    };
  }, [screen]);

  // Accent color CSS var
  const accent = tweaks.accent || "#E8001C";

  return (
    <div style={{
      minHeight: "100vh",
      background: `linear-gradient(180deg, #1a1a1a, #000)`,
      display: "flex", flexDirection: "column",
      alignItems: "center", padding: "24px 0",
      "--fk-accent": accent,
    }}>
      {/* Screen jump tabs (tweaks UI) */}
      <ScreenJumper
        current={screen}
        onJump={(s) => {
          if (s === "confirm") setOrderNumber(42);
          if (s === "cashInstruction") setOrderNumber(42);
          if (s === "wizard") setWizardProduct(PRODUCTS.tacos[2]); // tacos XL pour tester
          if (s === "menu" && cart.length === 0) {
            // Pré-remplit pour que le panier soit testable
            setCart([
              { name: "Tacos XL", emoji: "🌯", price: 11.90, qty: 1, options: "3 viandes · Sauce Algérienne, Biggy · Menu complet" },
              { name: "Coca-Cola 33cl", emoji: "🥤", price: 2.50, qty: 1 },
            ]);
          }
          setScreen(s);
        }}
        tweaks={tweaks}
        onTweak={updateTweak}
        onAdmin={() => setShowAdmin(true)}
        onInactivity={() => setShowInactivity(true)}
      />

      <FkKioskFrame showFrame={tweaks.showFrame !== false}>
        {/* Inject accent dynamically via inline style on body of kiosk */}
        <style>{`
          .fk-accent { color: ${accent}; }
        `}</style>

        {screen === "idle" && (
          <ScreenIdle onStart={() => setScreen("menu")} />
        )}

        {screen === "menu" && (
          <ScreenMenu
            cart={cart}
            onOpenProduct={(p) => { setWizardProduct(p); setScreen("wizard"); }}
            onGoCart={() => setScreen("cart")}
            onCancel={resetOrder}
          />
        )}

        {screen === "wizard" && wizardProduct && (
          <Wizard
            product={wizardProduct}
            onClose={() => { setWizardProduct(null); setScreen("menu"); }}
            onAddToCart={addToCart}
          />
        )}

        {screen === "cart" && (
          <ScreenCart
            cart={cart}
            dineIn={dineIn}
            setDineIn={setDineIn}
            showDineIn={true}
            onUpdateQty={(idx, q) => setCart(c => c.map((x, i) => i === idx ? { ...x, qty: q } : x))}
            onRemove={(idx) => setCart(c => c.filter((_, i) => i !== idx))}
            onBack={() => setScreen("menu")}
            onContinue={() => setScreen("upsell")}
          />
        )}

        {screen === "upsell" && (
          <ScreenUpsell
            cart={cart}
            onBack={() => setScreen("cart")}
            onSkip={() => setScreen("payment")}
            onAdd={(item) => { setCart(c => [...c, { ...item, qty: 1 }]); }}
          />
        )}

        {screen === "payment" && (
          <ScreenPaymentChoice
            total={cartTotal}
            onBack={() => setScreen("upsell")}
            onSelect={(method) => {
              if (method === "cb" || method === "tr") setScreen("tpe");
              else {
                setOrderNumber(Math.floor(Math.random() * 80) + 20);
                setScreen("cashInstruction");
              }
            }}
          />
        )}

        {screen === "cashInstruction" && orderNumber !== null && (
          <ScreenCashInstruction
            orderNumber={orderNumber}
            total={cartTotal}
            onContinue={() => setScreen("waiting")}
          />
        )}

        {screen === "error" && (
          <ScreenError
            type={tweaks.__errorType || "network"}
            onRetry={() => setScreen("menu")}
            onBack={resetOrder}
          />
        )}

        {screen === "tpe" && (
          <ScreenTPE
            total={cartTotal}
            onComplete={() => setScreen("waiting")}
            onCancel={() => setScreen("payment")}
          />
        )}

        {screen === "waiting" && (
          <ScreenWaiting onDone={() => {
            setOrderNumber(Math.floor(Math.random() * 80) + 20);
            setScreen("confirm");
          }} />
        )}

        {screen === "confirm" && orderNumber !== null && (
          <ScreenConfirmation
            orderNumber={orderNumber}
            total={cartTotal}
            dineIn={dineIn}
            onDone={resetOrder}
          />
        )}

        {/* Overlays */}
        {showInactivity && (
          <InactivityOverlay
            onContinue={() => setShowInactivity(false)}
            onRestart={() => { setShowInactivity(false); resetOrder(); }}
          />
        )}
        {showAdmin && (
          <AdminOverlay onClose={() => setShowAdmin(false)} />
        )}
      </FkKioskFrame>

      {/* Pied de page — info */}
      <div style={{
        color: "rgba(255,255,255,0.35)", fontSize: 12,
        marginTop: 24, letterSpacing: 2, textTransform: "uppercase",
      }}>
        FoodKing kiosk · 1080 × 1920 · {screen}
      </div>
    </div>
  );
}

// Screen jumper — nav + tweaks panel
function ScreenJumper({ current, onJump, tweaks, onTweak, onAdmin, onInactivity }) {
  const [open, setOpen] = React.useState(false);
  const screens = [
    { id: "idle", label: "Idle" },
    { id: "menu", label: "Menu" },
    { id: "wizard", label: "Wizard" },
    { id: "cart", label: "Panier" },
    { id: "upsell", label: "Upsell" },
    { id: "payment", label: "Paiement" },
    { id: "tpe", label: "TPE" },
    { id: "cashInstruction", label: "Espèces" },
    { id: "waiting", label: "Waiting" },
    { id: "confirm", label: "Confirm" },
    { id: "error", label: "Erreur" },
  ];

  const chipStyle = (active) => ({
    padding: "8px 14px",
    borderRadius: 999,
    background: active ? "#E8001C" : "rgba(255,255,255,0.08)",
    color: "#fff", fontSize: 12, fontWeight: 700,
    letterSpacing: 0.5, textTransform: "uppercase",
    cursor: "pointer", border: "none",
    transition: "background 120ms",
  });

  return (
    <>
      {/* Top nav */}
      <div style={{
        display: "flex", gap: 6, flexWrap: "wrap",
        justifyContent: "center", padding: "0 20px 18px",
        maxWidth: 960,
      }}>
        {screens.map(s => (
          <button key={s.id} onClick={() => onJump(s.id)} style={chipStyle(current === s.id)}>
            {s.label}
          </button>
        ))}
        <button onClick={onInactivity} style={{ ...chipStyle(false), background: "rgba(245,158,11,0.25)" }}>⏱ Inactivity</button>
        <button onClick={onAdmin} style={{ ...chipStyle(false), background: "rgba(220,38,38,0.25)" }}>🔧 Admin</button>
        <button onClick={() => setOpen(o => !o)} style={{ ...chipStyle(open), background: open ? "#E8001C" : "rgba(255,255,255,0.15)" }}>
          ⚙ Tweaks
        </button>
      </div>

      {/* Tweaks panel */}
      {open && (
        <div style={{
          background: "rgba(255,255,255,0.05)",
          border: "1px solid rgba(255,255,255,0.1)",
          backdropFilter: "blur(10px)",
          borderRadius: 20, padding: 20,
          marginBottom: 18, color: "#fff",
          minWidth: 540, maxWidth: 720,
          animation: "fkSlideIn 240ms ease-out",
        }}>
          <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: 2, textTransform: "uppercase", opacity: 0.6, marginBottom: 14 }}>
            Tweaks
          </div>

          {/* Accent color */}
          <div style={{ marginBottom: 16 }}>
            <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 8, opacity: 0.8 }}>Couleur brand</div>
            <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
              {[
                { id: "#E8001C", name: "FoodKing Red" },
                { id: "#FF6B00", name: "Orange" },
                { id: "#FFB400", name: "Ambre" },
                { id: "#16A34A", name: "Vert" },
                { id: "#7C3AED", name: "Violet" },
                { id: "#0EA5E9", name: "Bleu" },
              ].map(c => (
                <button key={c.id} onClick={() => onTweak("accent", c.id)} style={{
                  padding: "8px 14px",
                  borderRadius: 999,
                  background: tweaks.accent === c.id ? c.id : "rgba(255,255,255,0.06)",
                  border: tweaks.accent === c.id ? `2px solid #fff` : "2px solid transparent",
                  color: "#fff", fontSize: 12, fontWeight: 700,
                  cursor: "pointer",
                  display: "flex", alignItems: "center", gap: 8,
                }}>
                  <span style={{ width: 12, height: 12, borderRadius: "50%", background: c.id, boxShadow: "inset 0 0 0 1px rgba(0,0,0,0.2)" }} />
                  {c.name}
                </button>
              ))}
            </div>
          </div>

          {/* Frame */}
          <div style={{ marginBottom: 8 }}>
            <label style={{ display: "flex", alignItems: "center", gap: 10, fontSize: 14, cursor: "pointer" }}>
              <input
                type="checkbox"
                checked={tweaks.showFrame !== false}
                onChange={(e) => onTweak("showFrame", e.target.checked)}
                style={{ width: 18, height: 18 }}
              />
              Afficher le châssis physique de la borne
            </label>
          </div>

          <div style={{ fontSize: 11, opacity: 0.5, marginTop: 14, fontStyle: "italic" }}>
            💡 Astuce : utilise les onglets Idle / Menu / Wizard / … pour sauter directement à un écran.
          </div>
        </div>
      )}
    </>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
