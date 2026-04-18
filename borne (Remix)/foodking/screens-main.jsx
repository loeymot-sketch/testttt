/* ==========================================================================
   FoodKing Kiosk — Écrans principaux (idle, menu, panier, paiement, etc.)
   ========================================================================== */

// ========== Écran 1 — Attract Loop (idle) =================================
function ScreenIdle({ onStart }) {
  return (
    <div onClick={onStart} style={{
      width: "100%", height: "100%",
      background: `linear-gradient(180deg, #1a0305 0%, ${fk.red} 55%, #8a0010 100%)`,
      color: "#fff",
      position: "relative",
      overflow: "hidden",
      cursor: "pointer",
    }}>
      {/* floating food emojis in bg */}
      {["🍔", "🌯", "🍟", "🥤", "🍗", "🥖"].map((e, i) => (
        <div key={i} style={{
          position: "absolute",
          fontSize: 140,
          opacity: 0.08,
          top: `${(i * 17) % 80 + 8}%`,
          left: `${(i * 23) % 80 + 10}%`,
          transform: `rotate(${i * 20}deg)`,
          animation: `fkFloat ${4 + i}s ease-in-out infinite alternate`,
        }}>{e}</div>
      ))}

      <div style={{ padding: "140px 80px 60px", position: "relative", zIndex: 2 }}>
        <div style={{ fontSize: 42, fontWeight: 900, letterSpacing: 8, opacity: 0.9 }}>FOODKING</div>
        <div style={{ height: 6, width: 120, background: "#fff", borderRadius: 3, marginTop: 14, marginBottom: 36 }} />
        <div style={{ fontSize: 88, fontWeight: 900, lineHeight: 1, letterSpacing: -1 }}>
          Bienvenue<br/>chez<br/>FoodKing.
        </div>
        <div style={{ fontSize: 34, fontWeight: 500, marginTop: 28, opacity: 0.92, maxWidth: 820 }}>
          Commandez en quelques secondes.<br/>Retirez votre commande au comptoir.
        </div>
      </div>

      {/* Giant food placeholder */}
      <div style={{
        position: "absolute", top: "42%", left: "50%", transform: "translateX(-50%)",
        fontSize: 480, filter: "drop-shadow(0 40px 80px rgba(0,0,0,0.4))",
        animation: "fkFloat 3s ease-in-out infinite alternate",
      }}>🍔</div>

      {/* Bottom CTA - bouton géant pulsant */}
      <div style={{
        position: "absolute", bottom: 100, left: 60, right: 60,
      }}>
        <div style={{
          background: "#fff", color: fk.red,
          borderRadius: 44,
          padding: "46px 48px",
          textAlign: "center",
          animation: "fkPulse 1.8s ease-in-out infinite",
          boxShadow: "0 20px 60px rgba(0,0,0,0.35)",
        }}>
          <div style={{ fontSize: 26, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 4, marginBottom: 8 }}>
            ▼ Touchez l'écran ▼
          </div>
          <div style={{ fontSize: 72, fontWeight: 900, lineHeight: 1, letterSpacing: -1 }}>
            COMMANDER ICI
          </div>
        </div>
      </div>

      {/* Lang selector */}
      <div style={{ position: "absolute", top: 40, right: 40, display: "flex", gap: 10, zIndex: 3 }}
           onClick={(e) => e.stopPropagation()}>
        {["FR", "EN", "AR"].map(l => (
          <button key={l} onClick={(e) => e.stopPropagation()} style={{
            width: 60, height: 60, borderRadius: "50%",
            background: l === "FR" ? "#fff" : "rgba(255,255,255,0.15)",
            color: l === "FR" ? fk.red : "#fff",
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 18, fontWeight: 800, cursor: "pointer",
            border: "2px solid rgba(255,255,255,0.3)",
          }}>{l}</button>
        ))}
      </div>
    </div>
  );
}

// ========== Écran 2 — Catégories + Grille produits (cœur du parcours) =====
function ScreenMenu({ cart, onOpenProduct, onGoCart, onCancel }) {
  const [activeCat, setActiveCat] = React.useState(CATEGORIES[0].id);
  const products = PRODUCTS[activeCat] || [];
  const cartCount = cart.reduce((a, b) => a + b.qty, 0);
  const cartTotal = cart.reduce((a, b) => a + b.price * b.qty, 0);

  return (
    <div style={{ display: "grid", gridTemplateColumns: "260px 1fr", height: "100%", background: fk.bg }}>
      {/* === Sidebar gauche === */}
      <div style={{
        background: fk.surface,
        borderRight: `1px solid ${fk.border}`,
        overflowY: "auto",
        padding: "18px 0 120px",
      }}>
        {/* Logo */}
        <div style={{
          padding: "16px 20px 24px",
          textAlign: "center",
          borderBottom: `1px solid ${fk.border}`,
          marginBottom: 12,
        }}>
          <div style={{
            fontSize: 28, fontWeight: 900, color: fk.red, letterSpacing: 2,
          }}>FOODKING</div>
          <div style={{ height: 3, width: 40, background: fk.red, borderRadius: 2, margin: "6px auto 0" }} />
        </div>
        {CATEGORIES.map(c => {
          const active = c.id === activeCat;
          return (
            <div key={c.id}
              onClick={() => setActiveCat(c.id)}
              style={{
                padding: "14px 12px",
                margin: "4px 10px",
                borderRadius: 16,
                background: active ? fk.redSoft : "transparent",
                borderLeft: `4px solid ${active ? fk.red : "transparent"}`,
                display: "flex", flexDirection: "column", alignItems: "center", gap: 6,
                cursor: "pointer",
                transition: "background 140ms",
              }}>
              <div style={{
                width: 88, height: 88,
                borderRadius: "50%",
                background: active ? "#fff" : fk.surfaceAlt,
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 46,
                boxShadow: active ? fk.shadowCard : "none",
              }}>{c.emoji}</div>
              <div style={{
                fontSize: 13, fontWeight: 800,
                color: active ? fk.red : fk.text,
                textTransform: "uppercase", letterSpacing: 0.6,
                textAlign: "center", lineHeight: 1.15,
              }}>{c.name}</div>
            </div>
          );
        })}
      </div>

      {/* === Zone droite === */}
      <div style={{ display: "flex", flexDirection: "column", minWidth: 0 }}>
        {/* Header */}
        <div style={{
          padding: "22px 32px 10px",
          borderBottom: `1px solid ${fk.border}`,
          background: fk.surface,
          display: "flex", alignItems: "center", justifyContent: "space-between",
        }}>
          <div>
            <div style={{ fontSize: 14, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 1.5 }}>
              Nos spécialités
            </div>
            <div style={{ fontSize: 34, fontWeight: 900, color: fk.text, textTransform: "uppercase", letterSpacing: 0.6 }}>
              {CATEGORIES.find(c => c.id === activeCat)?.name}
            </div>
            <div style={{ fontSize: 14, color: fk.textMute, marginTop: 2 }}>{products.length} produits</div>
          </div>
          <div style={{ display: "flex", gap: 10 }}>
            <button style={pillBtn}>
              <span style={{ fontSize: 16 }}>👤</span>
              <span>Mon compte</span>
            </button>
            <button style={pillBtn}>
              <span style={{ fontSize: 16 }}>⚠</span>
              <span>Allergènes</span>
            </button>
            <button style={{ ...pillBtn, padding: "10px 14px" }}>🌐 FR</button>
          </div>
        </div>

        {/* Grille produits */}
        <div style={{ flex: 1, overflowY: "auto", padding: "22px 28px 280px" }}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 22 }}>
            {products.map(p => (
              <ProductCard key={p.id} product={p} onClick={() => onOpenProduct(p)} />
            ))}
          </div>
        </div>

        {/* Bandeau inférieur sticky */}
        <div style={{
          position: "absolute", bottom: 0, left: 260, right: 0,
          background: "#fff",
          borderTop: `1px solid ${fk.border}`,
          padding: "16px 28px 20px",
          boxShadow: "0 -8px 24px rgba(0,0,0,0.06)",
        }}>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12 }}>
            <div style={{
              display: "flex", alignItems: "center", gap: 12,
              cursor: cartCount > 0 ? "pointer" : "default",
              opacity: cartCount > 0 ? 1 : 0.5,
            }} onClick={cartCount > 0 ? onGoCart : undefined}>
              <div style={{
                width: 60, height: 60, borderRadius: "50%",
                background: fk.surfaceAlt,
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 28, position: "relative",
              }}>
                🛒
                {cartCount > 0 && (
                  <div style={{
                    position: "absolute", top: -4, right: -4,
                    background: fk.red, color: "#fff",
                    fontSize: 14, fontWeight: 800,
                    width: 28, height: 28, borderRadius: "50%",
                    display: "flex", alignItems: "center", justifyContent: "center",
                    border: "2px solid #fff",
                  }}>{cartCount}</div>
                )}
              </div>
              <div>
                <div style={{ fontSize: 14, color: fk.textSoft, textTransform: "uppercase", fontWeight: 700, letterSpacing: 1 }}>Panier</div>
                <div style={{ fontSize: 18, fontWeight: 700 }}>{cartCount} article{cartCount > 1 ? "s" : ""}</div>
              </div>
            </div>
            <div style={{ textAlign: "right" }}>
              <div style={{ fontSize: 14, color: fk.textSoft, textTransform: "uppercase", fontWeight: 700, letterSpacing: 1 }}>Total</div>
              <div style={{ fontSize: 34, fontWeight: 900, color: fk.red }}>
                {cartTotal.toFixed(2).replace(".", ",")} €
              </div>
            </div>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1.5fr", gap: 12 }}>
            <FkButton variant="ghost" onClick={onCancel} size="md">Abandonner</FkButton>
            <FkButton variant="primary" onClick={onGoCart} disabled={cartCount === 0} size="md">
              Payer — {cartTotal.toFixed(2).replace(".", ",")} €
            </FkButton>
          </div>
        </div>
      </div>
    </div>
  );
}

const pillBtn = {
  background: fk.surface,
  border: `2px solid ${fk.red}`,
  borderRadius: 999,
  padding: "10px 18px",
  fontSize: 14, fontWeight: 800,
  color: fk.red,
  cursor: "pointer",
  textTransform: "uppercase",
  letterSpacing: 0.5,
  display: "inline-flex", alignItems: "center", gap: 8,
  transition: "background 120ms, color 120ms",
};

// Card produit (grille 2 col)
function ProductCard({ product, onClick }) {
  const unavailable = product.unavailable;
  return (
    <div onClick={unavailable ? undefined : onClick}
      onMouseDown={e => { if (!unavailable) e.currentTarget.style.transform = "scale(0.98)"; }}
      onMouseUp={e => e.currentTarget.style.transform = ""}
      onMouseLeave={e => e.currentTarget.style.transform = ""}
      style={{
        background: fk.surface,
        borderRadius: 28,
        padding: 16,
        boxShadow: fk.shadowCard,
        cursor: unavailable ? "not-allowed" : "pointer",
        transition: "transform 140ms, box-shadow 200ms",
        position: "relative",
        overflow: "hidden",
        opacity: unavailable ? 0.55 : 1,
        filter: unavailable ? "grayscale(0.7)" : "none",
      }}>
      {/* Ruban NOUVEAU (style concurrent) */}
      {product.badge === "Nouveau" && (
        <div style={{
          position: "absolute", top: 18, right: -36,
          background: fk.red, color: "#fff",
          transform: "rotate(40deg)",
          fontSize: 11, fontWeight: 900, letterSpacing: 2,
          padding: "4px 40px",
          boxShadow: "0 4px 10px rgba(232,0,28,0.3)",
          zIndex: 2,
        }}>NOUVEAU</div>
      )}
      {product.badge && product.badge !== "Nouveau" && (
        <FkBadge color="red" style={{ position: "absolute", top: 14, left: 14, zIndex: 2 }}>
          {product.badge}
        </FkBadge>
      )}
      {/* Overlay EN RUPTURE */}
      {unavailable && (
        <div style={{
          position: "absolute", top: "32%", left: "50%",
          transform: "translate(-50%, -50%) rotate(-8deg)",
          background: "rgba(255,255,255,0.92)",
          border: `3px solid ${fk.error}`,
          borderRadius: 12, padding: "8px 22px",
          fontSize: 20, fontWeight: 900, color: fk.error,
          textTransform: "uppercase", letterSpacing: 2,
          zIndex: 3,
          boxShadow: "0 4px 14px rgba(220,38,38,0.25)",
        }}>En rupture</div>
      )}
      <div style={{ position: "relative", display: "flex", justifyContent: "center" }}>
        <FkProductImage emoji={product.emoji} size={220} bg={fk.surfaceAlt} />
        <div style={{ position: "absolute", bottom: -4, right: 12 }}>
          <FkPlusBtn onClick={(e) => { e.stopPropagation(); onClick(); }} size={64} />
        </div>
      </div>
      <div style={{ marginTop: 14 }}>
        <div style={{
          fontSize: 22, fontWeight: 800,
          color: fk.text, textTransform: "uppercase",
          letterSpacing: 0.4, lineHeight: 1.15,
        }}>{product.name}</div>
        <div style={{ fontSize: 14, color: fk.textSoft, marginTop: 4, minHeight: 36 }}>
          {product.desc}
        </div>
        <div style={{ fontSize: 26, fontWeight: 900, color: fk.red, marginTop: 8 }}>
          {product.price.toFixed(2).replace(".", ",")} €
        </div>
      </div>
    </div>
  );
}

// ========== Écran 5 — Panier ==============================================
function ScreenCart({ cart, onUpdateQty, onRemove, onBack, onContinue, showDineIn, dineIn, setDineIn }) {
  const subtotal = cart.reduce((a, b) => a + b.price * b.qty, 0);
  const tva = subtotal * (1 - 1/1.1); // TVA 10% foodservice
  const canContinue = cart.length > 0 && (!showDineIn || dineIn);

  return (
    <div style={{ height: "100%", display: "flex", flexDirection: "column", background: fk.bg }}>
      {/* Header */}
      <div style={{ background: "#fff", borderBottom: `1px solid ${fk.border}`, padding: "22px 28px", display: "flex", alignItems: "center", gap: 16 }}>
        <button onClick={onBack} style={{
          width: 64, height: 64, borderRadius: "50%",
          background: "#fff", border: `2px solid ${fk.border}`,
          fontSize: 28, cursor: "pointer", color: fk.textSoft,
        }}>‹</button>
        <div>
          <div style={{ fontSize: 14, fontWeight: 700, color: fk.textSoft, textTransform: "uppercase", letterSpacing: 1.5 }}>
            Votre commande
          </div>
          <div style={{ fontSize: 34, fontWeight: 900, textTransform: "uppercase" }}>Panier</div>
        </div>
      </div>

      {/* Zone scrollable */}
      <div style={{ flex: 1, overflowY: "auto", padding: "24px 26px 300px" }}>
        {/* Dine-in selector (CACHÉ mais code conservé) */}
        {showDineIn && (
          <div style={{ marginBottom: 22 }}>
            <div style={{ fontSize: 18, fontWeight: 800, marginBottom: 10, textTransform: "uppercase", letterSpacing: 0.6 }}>
              Où mangez-vous ?
            </div>
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
              {[
                { id: "dinein", label: "Sur place", emoji: "🍽️" },
                { id: "takeaway", label: "À emporter", emoji: "🛍️" },
              ].map(d => {
                const active = dineIn === d.id;
                return (
                  <div key={d.id} onClick={() => setDineIn(d.id)} style={{
                    background: active ? fk.red : "#fff",
                    color: active ? "#fff" : fk.text,
                    border: `3px solid ${active ? fk.red : fk.border}`,
                    borderRadius: 22, padding: 18,
                    display: "flex", alignItems: "center", gap: 16,
                    cursor: "pointer",
                    boxShadow: active ? fk.shadowCta : fk.shadowCard,
                    transform: active ? "translateY(-2px)" : "none",
                    transition: "all 180ms ease-out",
                  }}>
                    <div style={{ fontSize: 48, filter: active ? "drop-shadow(0 2px 4px rgba(0,0,0,0.2))" : "none" }}>{d.emoji}</div>
                    <div style={{ fontSize: 20, fontWeight: 900, textTransform: "uppercase", letterSpacing: 0.5 }}>{d.label}</div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* Items */}
        {cart.length === 0 ? (
          <div style={{ textAlign: "center", padding: "80px 20px", color: fk.textSoft }}>
            <div style={{ fontSize: 100, marginBottom: 20 }}>🛒</div>
            <div style={{ fontSize: 24, fontWeight: 700 }}>Votre panier est vide</div>
          </div>
        ) : (
          <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
            {cart.map((item, idx) => (
              <div key={idx} style={{
                background: "#fff",
                borderRadius: 20,
                padding: 16,
                display: "grid", gridTemplateColumns: "110px 1fr auto",
                gap: 16, alignItems: "center",
                boxShadow: fk.shadowCard,
              }}>
                <FkProductImage emoji={item.emoji} size={100} />
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontSize: 20, fontWeight: 800, textTransform: "uppercase" }}>{item.name}</div>
                  {item.options && (
                    <div style={{ fontSize: 14, color: fk.textSoft, marginTop: 4, lineHeight: 1.3 }}>
                      {item.options}
                    </div>
                  )}
                  <div style={{ fontSize: 22, fontWeight: 800, color: fk.red, marginTop: 6 }}>
                    {(item.price * item.qty).toFixed(2).replace(".", ",")} €
                  </div>
                </div>
                <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 10 }}>
                  <FkQty value={item.qty} onChange={(q) => onUpdateQty(idx, q)} />
                  <button onClick={() => onRemove(idx)} style={{
                    background: "transparent", border: "none",
                    color: fk.error, fontSize: 16, fontWeight: 700,
                    textTransform: "uppercase", letterSpacing: 0.5,
                    cursor: "pointer",
                  }}>🗑 Retirer</button>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Add more + promo */}
        <div style={{ marginTop: 22, display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
          <FkButton variant="secondary" onClick={onBack} size="md">+ Ajouter un article</FkButton>
          <FkButton variant="ghost" size="md">🎟 Code promo</FkButton>
        </div>
      </div>

      {/* Footer totals + continue */}
      <div style={{
        position: "absolute", bottom: 0, left: 0, right: 0,
        background: "#fff",
        borderTop: `1px solid ${fk.border}`,
        padding: "18px 26px 24px",
      }}>
        <div style={{ display: "flex", justifyContent: "space-between", fontSize: 16, color: fk.textSoft, marginBottom: 4 }}>
          <span>Sous-total (HT)</span><span>{(subtotal - tva).toFixed(2).replace(".", ",")} €</span>
        </div>
        <div style={{ display: "flex", justifyContent: "space-between", fontSize: 16, color: fk.textSoft, marginBottom: 8 }}>
          <span>TVA 10 %</span><span>{tva.toFixed(2).replace(".", ",")} €</span>
        </div>
        <div style={{ display: "flex", justifyContent: "space-between", fontSize: 32, fontWeight: 900, color: fk.text, marginBottom: 16 }}>
          <span>Total</span><span style={{ color: fk.red }}>{subtotal.toFixed(2).replace(".", ",")} €</span>
        </div>
        <FkButton variant="primary" fullWidth onClick={onContinue} disabled={!canContinue} size="lg">
          Continuer ›
        </FkButton>
      </div>
    </div>
  );
}

Object.assign(window, { ScreenIdle, ScreenMenu, ScreenCart, ProductCard });
