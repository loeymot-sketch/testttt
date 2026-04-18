/* ==========================================================================
   FoodKing Kiosk — Étapes du wizard (1 composant par étape)
   Chaque étape utilise useEffect pour (dé)bloquer le CTA "Suivant"
   ========================================================================== */

// ---- Step 1: Taille (tacos) ----------------------------------------------
function StepSize({ product, value, onChange }) {
  return (
    <StepShell question="Quelle taille souhaitez-vous ?">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20, padding: "0 32px" }}>
        {SIZES.map((s) => (
          <OptionCard key={s.id}
            selected={value === s.id}
            onClick={() => onChange(s.id)}
            emoji={s.emoji}
            emojiSize={96}
            title={s.name}
            subtitle={s.desc}
            priceDelta={s.delta}
            big
          />
        ))}
      </div>
    </StepShell>
  );
}

// ---- Step 2: Pain (sandwich) ---------------------------------------------
function StepPain({ value, onChange }) {
  return (
    <StepShell question="Quel pain ?">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20, padding: "0 32px" }}>
        {PAINS.map((p) => (
          <OptionCard key={p.id}
            selected={value === p.id}
            onClick={() => onChange(p.id)}
            emoji={p.emoji} emojiSize={96}
            title={p.name} subtitle={p.desc}
            big
          />
        ))}
      </div>
    </StepShell>
  );
}

// ---- Step 3: Viandes (multi, répétable) ----------------------------------
function StepViande({ product, value, onChange }) {
  const n = product.meats || 1;
  const total = value.reduce((a, b) => a + b.qty, 0);
  const [flash, setFlash] = React.useState(false);

  const toggle = (id) => {
    const cur = value.find(v => v.id === id);
    // Si déjà sélectionné et qu'on clique long-press → retirer
    // Ici: cliquer sur une viande existante l'INCRÉMENTE jusqu'à max.
    // Pour retirer, on a la petite corbeille sur la card (dans counter).
    if (total >= n) {
      // Plein. Ne rien faire, juste flasher le compteur.
      setFlash(true);
      setTimeout(() => setFlash(false), 500);
      return;
    }
    if (cur) {
      onChange(value.map(v => v.id === id ? { ...v, qty: v.qty + 1 } : v));
    } else {
      onChange([...value, { id, qty: 1 }]);
    }
  };

  const decrement = (id, e) => {
    e.stopPropagation();
    const cur = value.find(v => v.id === id);
    if (!cur) return;
    if (cur.qty <= 1) onChange(value.filter(v => v.id !== id));
    else onChange(value.map(v => v.id === id ? { ...v, qty: v.qty - 1 } : v));
  };

  const badgeColor = total >= n ? fk.success : (flash ? fk.red : fk.textSoft);
  const infoLabel = total >= n
    ? `✓ ${total} / ${n} — Vous pouvez modifier ou passer à l'étape suivante`
    : `${total} / ${n} sélectionnée${n > 1 ? "s" : ""} — Touchez pour ajouter`;

  return (
    <StepShell question={`Choisissez ${n} viande${n > 1 ? "s" : ""}`}>
      <div style={{
        textAlign: "center", padding: "0 32px 14px",
        fontSize: 17, fontWeight: 700, color: badgeColor,
        transform: flash ? "scale(1.06)" : "scale(1)",
        transition: "transform 200ms, color 200ms",
      }}>
        {infoLabel}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 18, padding: "0 28px" }}>
        {VIANDES.map((v) => {
          const cur = value.find(x => x.id === v.id);
          const qty = cur ? cur.qty : 0;
          return (
            <div key={v.id} style={{ position: "relative" }}>
              <OptionCard
                selected={qty > 0}
                onClick={() => toggle(v.id)}
                emoji={v.emoji} emojiSize={68}
                title={v.name}
                counter={qty > 0 ? `× ${qty}` : null}
                dimmed={qty === 0 && total >= n}
              />
              {qty > 0 && (
                <button onClick={(e) => decrement(v.id, e)} style={{
                  position: "absolute", bottom: 10, left: 10,
                  width: 40, height: 40, borderRadius: "50%",
                  background: "#fff", border: `2px solid ${fk.border}`,
                  fontSize: 22, fontWeight: 800, color: fk.text,
                  cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center",
                  boxShadow: fk.shadowCard,
                }}>−</button>
              )}
            </div>
          );
        })}
      </div>
    </StepShell>
  );
}

// ---- Step 4: Sauces (multi avec 1ère gratuite) ---------------------------
// Valeur: array (peut être vide + noSauce via prop séparée suivie en state parent).
// On utilise un sentinel "__NOSAUCE__" dans l'array pour indiquer "sans sauce".
function StepSauce({ value, onChange, title }) {
  const NOSAUCE = "__NOSAUCE__";
  const isNoSauce = value.length === 1 && value[0] === NOSAUCE;
  const sauceList = value.filter(x => x !== NOSAUCE);

  const toggle = (id) => {
    // Toggle on: retire le marqueur sans-sauce
    if (sauceList.includes(id)) {
      onChange(sauceList.filter(x => x !== id));
    } else {
      onChange([...sauceList, id]);
    }
  };
  const pickNoSauce = () => {
    if (isNoSauce) onChange([]); // désélectionne (revient en "pas encore choisi")
    else onChange([NOSAUCE]);
  };
  return (
    <StepShell
      question={title || "Quelle sauce ?"}
      info="1ère gratuite · +0,50 € par sauce supplémentaire"
    >
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr", gap: 14, padding: "0 28px" }}>
        {SAUCES.map((s) => {
          const order = sauceList.indexOf(s.id);
          const selected = order >= 0;
          const extra = order > 0;
          return (
            <OptionCard
              key={s.id}
              selected={selected}
              onClick={() => toggle(s.id)}
              emoji="🥫" emojiSize={48}
              title={s.name}
              number={selected ? order + 1 : null}
              subtitle={extra ? "+0,50 €" : (selected ? "Offerte" : null)}
              compact
              dimmed={isNoSauce}
            />
          );
        })}
        {/* Sans sauce option — pleine largeur visuelle */}
        <OptionCard
          selected={isNoSauce}
          onClick={pickNoSauce}
          emoji="🚫" emojiSize={48}
          title="Sans sauce"
          compact
          dimmed={sauceList.length > 0}
        />
      </div>
    </StepShell>
  );
}

// ---- Step 5: Garnitures (gratuit, multi) ---------------------------------
function StepGarniture({ value, onChange }) {
  const toggle = (id) => {
    if (value.includes(id)) onChange(value.filter(x => x !== id));
    else onChange([...value, id]);
  };
  return (
    <StepShell question="Quelles crudités ?">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 18, padding: "0 28px" }}>
        {GARNITURES.map((g) => (
          <OptionCard
            key={g.id}
            selected={value.includes(g.id)}
            onClick={() => toggle(g.id)}
            emoji={g.emoji} emojiSize={68}
            title={g.name}
          />
        ))}
      </div>
    </StepShell>
  );
}

// ---- Step 6: Suppléments (payant, multi) + popup viande -----------------
function StepSupplement({ value, onChange, viandeValue = [], onViandeChange }) {
  const [showMeatPopup, setShowMeatPopup] = React.useState(false);
  const toggle = (id) => {
    if (value.includes(id)) onChange(value.filter(x => x !== id));
    else onChange([...value, id]);
  };
  const meatCount = viandeValue.length;
  return (
    <StepShell question="Un petit supplément ?" info="Optionnel · ajoutez autant de suppléments que vous voulez">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 18, padding: "0 28px" }}>
        {/* Carte spéciale "+ Viande" — ouvre une popup */}
        <div
          onClick={() => setShowMeatPopup(true)}
          style={{
            background: meatCount > 0 ? fk.red : "#fff",
            color: meatCount > 0 ? "#fff" : fk.text,
            border: `3px solid ${meatCount > 0 ? fk.red : fk.border}`,
            borderRadius: 22, padding: "18px 14px",
            display: "flex", flexDirection: "column", alignItems: "center", gap: 8,
            cursor: "pointer",
            boxShadow: meatCount > 0 ? fk.shadowCta : fk.shadowCard,
            position: "relative",
            transition: "all 180ms ease-out",
            transform: meatCount > 0 ? "translateY(-2px)" : "none",
          }}
        >
          {meatCount > 0 && (
            <div style={{
              position: "absolute", top: -10, right: -10,
              background: "#fff", color: fk.red,
              width: 38, height: 38, borderRadius: "50%",
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: 18, fontWeight: 900,
              boxShadow: "0 4px 12px rgba(0,0,0,0.2)",
              border: `2px solid ${fk.red}`,
            }}>{meatCount}</div>
          )}
          <div style={{ fontSize: 56 }}>🥩</div>
          <div style={{ fontSize: 16, fontWeight: 800, textTransform: "uppercase", textAlign: "center", lineHeight: 1.1 }}>
            Ajouter<br/>viande
          </div>
          <div style={{ fontSize: 13, fontWeight: 700, opacity: 0.9 }}>
            {meatCount > 0 ? `${meatCount} × 2,00 €` : "+2,00 € / viande"}
          </div>
        </div>

        {SUPPLEMENTS.map((s) => (
          <OptionCard
            key={s.id}
            selected={value.includes(s.id)}
            onClick={() => toggle(s.id)}
            emoji={s.emoji} emojiSize={68}
            title={s.name}
            priceDelta={s.price}
          />
        ))}
      </div>

      {showMeatPopup && (
        <MeatSupplementPopup
          value={viandeValue}
          onChange={onViandeChange}
          onClose={() => setShowMeatPopup(false)}
        />
      )}
    </StepShell>
  );
}

// ---- Popup : sélection de suppléments viande ----------------------------
function MeatSupplementPopup({ value, onChange, onClose }) {
  const counts = {};
  value.forEach(v => { counts[v.suppId] = (counts[v.suppId] || 0) + 1; });
  const total = value.reduce((a, v) => {
    const supp = SUPPLEMENT_MEAT_OPTIONS.find(s => s.id === v.suppId);
    return a + (supp?.price || 0);
  }, 0);

  const addOne = (suppId) => {
    onChange([...value, { suppId, key: `${suppId}-${Date.now()}` }]);
  };
  const removeOne = (suppId) => {
    const idx = value.findIndex(v => v.suppId === suppId);
    if (idx >= 0) {
      const next = [...value];
      next.splice(idx, 1);
      onChange(next);
    }
  };

  return (
    <div style={{
      position: "absolute", inset: 0,
      background: "rgba(20,20,20,0.55)",
      display: "flex", alignItems: "center", justifyContent: "center",
      zIndex: 50,
      animation: "fkFadeZoom 200ms ease-out",
      padding: 40,
    }} onClick={onClose}>
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          background: fk.bg, borderRadius: 32,
          padding: "34px 28px 24px", maxWidth: 920, width: "100%",
          boxShadow: "0 24px 64px rgba(0,0,0,0.35)",
          border: `4px solid ${fk.red}`,
        }}
      >
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 20 }}>
          <div>
            <div style={{ fontSize: 14, fontWeight: 700, color: fk.red, textTransform: "uppercase", letterSpacing: 2 }}>Supplément Viande</div>
            <div style={{ fontSize: 32, fontWeight: 900, color: fk.text, marginTop: 4 }}>Quelle viande ?</div>
          </div>
          <button onClick={onClose} style={{
            width: 56, height: 56, borderRadius: "50%",
            background: "#fff", border: `2px solid ${fk.border}`,
            fontSize: 28, fontWeight: 900, color: fk.textSoft, cursor: "pointer",
          }}>✕</button>
        </div>

        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16, marginBottom: 20 }}>
          {SUPPLEMENT_MEAT_OPTIONS.map(opt => {
            const n = counts[opt.id] || 0;
            return (
              <div key={opt.id} style={{
                background: "#fff", borderRadius: 22, padding: "18px 14px",
                display: "flex", flexDirection: "column", alignItems: "center", gap: 10,
                border: `3px solid ${n > 0 ? fk.red : fk.border}`,
                boxShadow: fk.shadowCard,
                transition: "all 180ms",
                transform: n > 0 ? "translateY(-2px)" : "none",
              }}>
                <div style={{ fontSize: 68 }}>{opt.emoji}</div>
                <div style={{ fontSize: 18, fontWeight: 900, textAlign: "center", textTransform: "uppercase" }}>{opt.name}</div>
                <div style={{ fontSize: 18, fontWeight: 900, color: fk.red }}>+{opt.price.toFixed(2).replace(".", ",")} €</div>
                <div style={{ display: "flex", alignItems: "center", gap: 12, marginTop: 4 }}>
                  <button onClick={() => removeOne(opt.id)} disabled={n === 0} style={{
                    width: 46, height: 46, borderRadius: "50%",
                    background: n === 0 ? "#f0ebe0" : "#fff",
                    border: `2px solid ${n === 0 ? fk.border : fk.red}`,
                    color: n === 0 ? fk.textMute : fk.red,
                    fontSize: 24, fontWeight: 900, cursor: n === 0 ? "not-allowed" : "pointer",
                  }}>−</button>
                  <div style={{ fontSize: 24, fontWeight: 900, minWidth: 28, textAlign: "center" }}>{n}</div>
                  <button onClick={() => addOne(opt.id)} style={{
                    width: 46, height: 46, borderRadius: "50%",
                    background: fk.red, border: "none", color: "#fff",
                    fontSize: 26, fontWeight: 900, cursor: "pointer",
                    boxShadow: fk.shadowCta,
                  }}>+</button>
                </div>
              </div>
            );
          })}
        </div>

        <div style={{
          background: "#fff", borderRadius: 18, padding: "16px 20px",
          display: "flex", alignItems: "center", justifyContent: "space-between",
          marginBottom: 16, border: `2px solid ${fk.border}`,
        }}>
          <div style={{ fontSize: 16, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 1 }}>
            Total suppléments
          </div>
          <div style={{ fontSize: 26, fontWeight: 900, color: fk.red }}>
            {total.toFixed(2).replace(".", ",")} €
          </div>
        </div>

        <FkButton variant="primary" fullWidth size="lg" onClick={onClose}>
          Valider ›
        </FkButton>
      </div>
    </div>
  );
}

// ---- Step 7: Menu (formule) - 4 cards ------------------------------------
function StepMenu({ value, onChange, hideBoissonSeule }) {
  const choices = hideBoissonSeule ? MENU_CHOICES.filter(m => m.id !== "boisson") : MENU_CHOICES;
  return (
    <StepShell question="Quel menu ?">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20, padding: "0 32px" }}>
        {choices.map((m) => (
          <OptionCard key={m.id}
            selected={value === m.id}
            onClick={() => onChange(m.id)}
            emoji={m.emoji} emojiSize={72}
            title={m.name}
            subtitle={m.desc}
            priceDelta={m.delta}
            big
          />
        ))}
      </div>
    </StepShell>
  );
}

// ---- Step 7a: Upgrade frites ---------------------------------------------
function StepFriteUpgrade({ value, onChange }) {
  return (
    <StepShell question="Vos frites ?">
      <div style={{ display: "grid", gridTemplateColumns: "1fr", gap: 16, padding: "0 32px" }}>
        {FRITES_UPGRADE.map((f) => (
          <OptionCard key={f.id}
            selected={value === f.id}
            onClick={() => onChange(f.id)}
            emoji={f.emoji} emojiSize={90}
            title={f.name}
            subtitle={f.desc}
            priceDelta={f.delta}
            horizontal
          />
        ))}
      </div>
    </StepShell>
  );
}

// ---- Step 7b: Boisson incluse --------------------------------------------
function StepBoisson({ value, onChange }) {
  return (
    <StepShell question="Quelle boisson ?" info="Incluse dans le menu">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr", gap: 14, padding: "0 28px" }}>
        {BOISSONS_MENU.map((b) => (
          <OptionCard key={b.id}
            selected={value === b.id}
            onClick={() => onChange(b.id)}
            emoji={b.emoji} emojiSize={56}
            title={b.name}
            compact
          />
        ))}
      </div>
    </StepShell>
  );
}

// ---- Step 7c: Sauce frites -----------------------------------------------
function StepSauceFrite({ value, onChange }) {
  return <StepSauce value={value} onChange={onChange} title="Quelle sauce pour vos frites ?" />;
}

// ==========================================================================
// Helpers: OptionCard (carte produit option cliquable style GUR KEBAB)
// ==========================================================================
function OptionCard({
  selected, onClick, emoji, emojiSize = 64,
  title, subtitle, priceDelta, counter, number, badge,
  big, compact, horizontal, dimmed,
}) {
  const base = {
    background: fk.surface,
    border: `2px solid ${selected ? fk.red : "transparent"}`,
    borderRadius: 22,
    padding: compact ? 14 : big ? 22 : 18,
    cursor: "pointer",
    position: "relative",
    transition: "transform 140ms ease, border-color 140ms ease, box-shadow 140ms ease, opacity 140ms ease",
    boxShadow: selected ? "0 6px 18px rgba(232,0,28,0.2)" : fk.shadowCard,
    opacity: dimmed && !selected ? 0.45 : 1,
    display: horizontal ? "grid" : "flex",
    gridTemplateColumns: horizontal ? "auto 1fr auto" : undefined,
    flexDirection: horizontal ? undefined : "column",
    alignItems: "center",
    gap: horizontal ? 20 : 10,
    textAlign: horizontal ? "left" : "center",
    minHeight: big ? 260 : compact ? 150 : 200,
  };
  const onDown = (e) => e.currentTarget.style.transform = "scale(0.97)";
  const onUp = (e) => e.currentTarget.style.transform = "";
  return (
    <div style={base} onClick={onClick} onMouseDown={onDown} onMouseUp={onUp} onMouseLeave={onUp}>
      <FkProductImage emoji={emoji} size={emojiSize * 1.7} style={{ width: emojiSize * 1.7, height: emojiSize * 1.7 }} />
      <div style={{ flex: horizontal ? 1 : undefined, minWidth: 0 }}>
        <div style={{
          fontSize: compact ? 15 : big ? 22 : 18,
          fontWeight: 800,
          color: fk.text,
          textTransform: "uppercase",
          letterSpacing: 0.4,
          lineHeight: 1.15,
        }}>{title}</div>
        {subtitle && (
          <div style={{ fontSize: compact ? 13 : 15, color: fk.textSoft, marginTop: 4, fontWeight: 500 }}>{subtitle}</div>
        )}
        {priceDelta > 0 && (
          <div style={{ fontSize: 18, fontWeight: 800, color: fk.red, marginTop: 6 }}>
            +{priceDelta.toFixed(2).replace(".", ",")} €
          </div>
        )}
      </div>
      {/* Top-right indicator: +/✓/number */}
      <div style={{ position: horizontal ? "relative" : "absolute", top: horizontal ? "auto" : -12, right: horizontal ? "auto" : -12 }}>
        <FkPlusBtn selected={selected} number={number} size={horizontal ? 56 : 48} onClick={(e) => { e.stopPropagation(); onClick(); }} />
      </div>
      {counter && (
        <div style={{
          position: "absolute", top: 12, left: 12,
          background: fk.red, color: "#fff",
          borderRadius: 999, padding: "4px 12px",
          fontSize: 16, fontWeight: 800,
        }}>{counter}</div>
      )}
      {badge && (
        <FkBadge style={{ position: "absolute", top: 12, left: 12 }}>{badge}</FkBadge>
      )}
    </div>
  );
}

// ---- Shell pour chaque étape (titre + contenu scrollable) ----------------
function StepShell({ question, info, children }) {
  return (
    <div style={{ padding: "20px 0 24px", animation: "fkSlideIn 240ms ease-out" }}>
      <div style={{
        fontSize: 30, fontWeight: 800,
        color: fk.text, textAlign: "center",
        padding: "0 32px 6px",
        letterSpacing: 0.4,
      }}>
        {question}
      </div>
      {info && (
        <div style={{
          fontSize: 16, color: fk.textSoft,
          textAlign: "center", padding: "0 32px 18px",
          fontWeight: 600,
        }}>{info}</div>
      )}
      {!info && <div style={{ height: 18 }} />}
      {children}
    </div>
  );
}

// ---- Récap final ---------------------------------------------------------
function StepRecap({ product, selections, totalPrice, qty, onQty, onAdd }) {
  const rows = [];
  if (selections.size) rows.push({ label: "Taille", value: selections.size });
  if (selections.pain) rows.push({ label: "Pain", value: PAINS.find(p => p.id === selections.pain)?.name });
  if (selections.meat?.length) {
    const meats = selections.meat.map(m => {
      const v = VIANDES.find(x => x.id === m.id);
      return `${v?.name}${m.qty > 1 ? " × " + m.qty : ""}`;
    }).join(", ");
    rows.push({ label: "Viandes", value: meats });
  }
  if (selections.sauce?.length) {
    if (selections.sauce.length === 1 && selections.sauce[0] === "__NOSAUCE__") {
      rows.push({ label: "Sauces", value: "Sans sauce" });
    } else {
      const sauces = selections.sauce.filter(s => s !== "__NOSAUCE__").map(s => SAUCES.find(x => x.id === s)?.name).join(", ");
      rows.push({ label: "Sauces", value: sauces });
    }
  }
  if (selections.garniture?.length) {
    const g = selections.garniture.map(id => GARNITURES.find(x => x.id === id)?.name).join(", ");
    rows.push({ label: "Crudités", value: g });
  }
  if (selections.supplement?.length) {
    const s = selections.supplement.map(id => SUPPLEMENTS.find(x => x.id === id)?.name).join(", ");
    rows.push({ label: "Suppléments", value: s });
  }
  if (selections.supplementViande?.length) {
    const counts = {};
    selections.supplementViande.forEach(v => { counts[v.suppId] = (counts[v.suppId] || 0) + 1; });
    const parts = Object.entries(counts).map(([id, n]) => {
      const opt = SUPPLEMENT_MEAT_OPTIONS.find(x => x.id === id);
      return `${opt?.name}${n > 1 ? ' × ' + n : ''}`;
    });
    rows.push({ label: "Suppl. viande", value: parts.join(", ") });
  }
  if (selections.menu && selections.menu !== "aucun") rows.push({ label: "Menu", value: MENU_CHOICES.find(m => m.id === selections.menu)?.name });
  if (selections.friteUp && selections.friteUp !== "standard") {
    rows.push({ label: "Frites", value: FRITES_UPGRADE.find(f => f.id === selections.friteUp)?.name });
  }
  if (selections.boisson) rows.push({ label: "Boisson", value: BOISSONS_MENU.find(b => b.id === selections.boisson)?.name });
  if (selections.sauceFrite?.length) {
    const sf = selections.sauceFrite.map(s => SAUCES.find(x => x.id === s)?.name).join(", ");
    rows.push({ label: "Sauce frites", value: sf });
  }

  return (
    <StepShell question="Votre article">
      <div style={{ padding: "0 28px" }}>
        {/* Photo + titre + prix */}
        <div style={{
          background: fk.surface,
          borderRadius: 24,
          padding: 24,
          display: "flex", alignItems: "center", gap: 22,
          boxShadow: fk.shadowCard,
          marginBottom: 20,
        }}>
          <FkProductImage emoji={product.emoji} size={130} />
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 26, fontWeight: 800, color: fk.text, textTransform: "uppercase" }}>{product.name}</div>
            <div style={{ fontSize: 16, color: fk.textSoft, marginTop: 4 }}>{product.desc}</div>
            <div style={{ fontSize: 30, fontWeight: 900, color: fk.red, marginTop: 10 }}>
              {totalPrice.toFixed(2).replace(".", ",")} €
            </div>
          </div>
        </div>

        {/* Options list */}
        <div style={{ background: fk.surface, borderRadius: 24, padding: 8, boxShadow: fk.shadowCard, marginBottom: 20 }}>
          {rows.length === 0 ? (
            <div style={{ padding: 20, textAlign: "center", color: fk.textSoft, fontSize: 16 }}>Aucune option à configurer.</div>
          ) : rows.map((r, i) => (
            <div key={i} style={{
              padding: "14px 18px",
              borderBottom: i < rows.length - 1 ? `1px solid ${fk.border}` : "none",
              display: "flex", justifyContent: "space-between", gap: 16,
            }}>
              <div style={{ fontSize: 15, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 0.5, minWidth: 130 }}>
                {r.label}
              </div>
              <div style={{ fontSize: 18, fontWeight: 600, color: fk.text, textAlign: "right", flex: 1 }}>
                {r.value}
              </div>
            </div>
          ))}
        </div>

        {/* Quantité */}
        <div style={{
          background: fk.surface, borderRadius: 24, padding: 20,
          display: "flex", alignItems: "center", justifyContent: "space-between",
          boxShadow: fk.shadowCard,
        }}>
          <div style={{ fontSize: 20, fontWeight: 800, textTransform: "uppercase", letterSpacing: 0.5 }}>
            Quantité
          </div>
          <FkQty value={qty} onChange={onQty} />
        </div>
      </div>
    </StepShell>
  );
}

Object.assign(window, {
  StepSize, StepPain, StepViande, StepSauce, StepGarniture,
  StepSupplement, StepMenu, StepFriteUpgrade, StepBoisson,
  StepSauceFrite, StepRecap, OptionCard, StepShell,
});
