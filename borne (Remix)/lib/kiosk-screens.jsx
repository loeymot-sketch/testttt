// Écrans de la borne — flow complet
// Screen components receive {state, dispatch, theme}
// state: { screen, cart, orderType, wizardItem, addedItem, orderId }

const { CATEGORIES, ITEMS, BURGER_WIZARD_STEPS, fmt } = window.KIOSK_DATA;

// ═══════════════════════════════════════════════════════════════════════════
// ATTRACT / IDLE — Écran d'accueil animé
// ═══════════════════════════════════════════════════════════════════════════
function AttractScreen({ dispatch, theme }) {
  const [tick, setTick] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setTick(t => t + 1), 3200);
    return () => clearInterval(id);
  }, []);
  const heroItems = [
    { emoji: '🍔', hue: 20, name: 'Double Beef', price: 10.50 },
    { emoji: '🍟', hue: 48, name: 'Frites XL', price: 4.50 },
    { emoji: '🥤', hue: 200, name: 'Coca 33cl', price: 2.90 },
  ];
  const hero = heroItems[tick % heroItems.length];

  return (
    <div onClick={() => dispatch({ type: 'NAV', screen: 'orderType' })} style={{
      height: '100%', width: '100%', position: 'relative', cursor: 'pointer',
      background: `radial-gradient(ellipse at 50% 30%, oklch(35% 0.18 ${hero.hue}) 0%, ${theme.bg} 70%)`,
      display: 'flex', flexDirection: 'column',
      overflow: 'hidden',
    }}>
      {/* Logo + tagline */}
      <div style={{ paddingTop: 120, textAlign: 'center', position: 'relative', zIndex: 2 }}>
        <div style={{
          width: 140, height: 140, borderRadius: 32,
          background: theme.accent, color: theme.onAccent,
          display: 'grid', placeItems: 'center',
          fontSize: 72, fontWeight: 900,
          margin: '0 auto 40px',
          boxShadow: `0 20px 60px ${theme.accent}88`,
        }}>GH</div>
        <div style={{ fontSize: 72, fontWeight: 900, color: theme.text, letterSpacing: '-0.03em', lineHeight: 1 }}>GRILL HOUSE</div>
        <div style={{ fontSize: 26, color: theme.textDim, letterSpacing: '0.3em', marginTop: 16, textTransform: 'uppercase' }}>Burger · Grill · Smoke</div>
      </div>

      {/* Hero rotating food */}
      <div key={tick} style={{
        flex: 1,
        display: 'grid', placeItems: 'center',
        position: 'relative',
        animation: 'kioskFadeZoom 3.2s ease-out',
      }}>
        <div style={{
          fontSize: 480,
          filter: 'drop-shadow(0 40px 80px rgba(0,0,0,0.6))',
        }}>{hero.emoji}</div>
        <div style={{
          position: 'absolute',
          bottom: 40, left: '50%', transform: 'translateX(-50%)',
          textAlign: 'center',
        }}>
          <div style={{ fontSize: 44, fontWeight: 800, color: theme.text }}>{hero.name}</div>
          <div style={{ fontSize: 58, fontWeight: 900, color: theme.accent, marginTop: 8, letterSpacing: '-0.02em' }}>{fmt(hero.price)}</div>
        </div>
      </div>

      {/* CTA */}
      <div style={{
        paddingBottom: 100,
        textAlign: 'center',
        position: 'relative',
      }}>
        <div style={{
          display: 'inline-flex', alignItems: 'center', gap: 20,
          padding: '32px 60px',
          borderRadius: 100,
          background: theme.accent, color: theme.onAccent,
          fontSize: 40, fontWeight: 800,
          boxShadow: `0 20px 60px ${theme.accent}88`,
          animation: 'kioskPulse 2s ease-in-out infinite',
        }}>
          <span style={{ fontSize: 44 }}>👆</span>
          Touchez l'écran pour commander
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// ORDER TYPE — Sur place / À emporter
// ═══════════════════════════════════════════════════════════════════════════
function OrderTypeScreen({ dispatch, theme }) {
  const [selected, setSelected] = useState(null);
  const pick = (type) => {
    setSelected(type);
    setTimeout(() => dispatch({ type: 'SET_ORDER_TYPE', orderType: type }), 350);
  };
  const Card = ({ type, icon, title, subtitle, bgHue }) => (
    <button onClick={() => pick(type)} style={{
      flex: 1,
      aspectRatio: '0.9',
      borderRadius: 40,
      border: 'none',
      background: theme.surface,
      cursor: 'pointer',
      position: 'relative',
      padding: 0,
      overflow: 'hidden',
      transition: 'transform 200ms, box-shadow 200ms',
      transform: selected === type ? 'scale(1.04)' : 'scale(1)',
      boxShadow: selected === type
        ? `0 30px 80px ${theme.accent}66, inset 0 0 0 4px ${theme.accent}`
        : `0 10px 30px ${theme.shadow}, inset 0 0 0 2px ${theme.border}`,
    }}>
      <div style={{
        height: '60%',
        background: `radial-gradient(circle at 50% 60%, oklch(55% 0.12 ${bgHue}) 0%, oklch(30% 0.1 ${bgHue}) 100%)`,
        display: 'grid', placeItems: 'center',
        fontSize: 280,
        filter: 'drop-shadow(0 20px 30px rgba(0,0,0,0.3))',
      }}>{icon}</div>
      <div style={{ padding: '40px 32px', textAlign: 'center' }}>
        <div style={{ fontSize: 52, fontWeight: 800, color: theme.text, letterSpacing: '-0.02em' }}>{title}</div>
        <div style={{ fontSize: 26, color: theme.textDim, marginTop: 8 }}>{subtitle}</div>
      </div>
    </button>
  );
  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', background: theme.bg }}>
      <TopBar theme={theme} />
      <div style={{ flex: 1, padding: '80px 60px', display: 'flex', flexDirection: 'column' }}>
        <div style={{ textAlign: 'center', marginBottom: 80 }}>
          <div style={{ fontSize: 30, color: theme.accent, fontWeight: 700, letterSpacing: '0.2em', textTransform: 'uppercase' }}>Bienvenue</div>
          <div style={{ fontSize: 72, fontWeight: 900, color: theme.text, letterSpacing: '-0.03em', marginTop: 16, lineHeight: 1.05 }}>
            Comment vous<br/>régalez-vous ?
          </div>
        </div>
        <div style={{ flex: 1, display: 'flex', gap: 40, alignItems: 'stretch' }}>
          <Card type="dine_in"   icon="🍽️" title="Sur place"   subtitle="Je mange ici" bgHue={30} />
          <Card type="takeaway"  icon="🥡" title="À emporter"  subtitle="Je pars avec" bgHue={150} />
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// MENU — Catégories + produits
// ═══════════════════════════════════════════════════════════════════════════
function MenuScreen({ state, dispatch, theme }) {
  const [cat, setCat] = useState('menus');
  const category = CATEGORIES.find(c => c.id === cat);
  const items = ITEMS[cat] || [];
  const cartCount = state.cart.reduce((s, i) => s + i.qty, 0);
  const cartTotal = state.cart.reduce((s, i) => s + i.qty * i.price, 0);

  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', background: theme.bg }}>
      <TopBar theme={theme} orderType={state.orderType} onLogoClick={() => dispatch({ type: 'NAV', screen: 'attract' })} />

      {/* Categories rail */}
      <div style={{
        padding: '24px 24px 20px',
        display: 'flex',
        gap: 14,
        overflowX: 'auto',
        background: theme.topBarBg,
        borderBottom: `1px solid ${theme.border}`,
      }}>
        {CATEGORIES.map(c => {
          const active = c.id === cat;
          return (
            <button key={c.id} onClick={() => setCat(c.id)} style={{
              flex: '0 0 auto',
              padding: '16px 24px',
              borderRadius: 20,
              border: active ? `3px solid ${theme.accent}` : `2px solid ${theme.border}`,
              background: active ? theme.accent : theme.surface,
              color: active ? theme.onAccent : theme.text,
              fontSize: 22,
              fontWeight: 700,
              cursor: 'pointer',
              fontFamily: 'inherit',
              display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4,
              minWidth: 128,
              transition: 'all 200ms',
              boxShadow: active ? `0 10px 24px ${theme.accent}55` : 'none',
            }}>
              <span style={{ fontSize: 40 }}>{c.emoji}</span>
              <span>{c.name}</span>
            </button>
          );
        })}
      </div>

      {/* Category header */}
      <div style={{ padding: '32px 40px 20px' }}>
        <div style={{ fontSize: 20, fontWeight: 700, color: theme.accent, letterSpacing: '0.2em', textTransform: 'uppercase' }}>
          {category.tagline}
        </div>
        <div style={{ fontSize: 54, fontWeight: 900, color: theme.text, letterSpacing: '-0.02em' }}>{category.name}</div>
      </div>

      {/* Product grid — 2 cols */}
      <div style={{
        flex: 1, overflowY: 'auto',
        padding: '0 32px 200px',
        display: 'grid',
        gridTemplateColumns: '1fr 1fr',
        gap: 20,
        alignContent: 'start',
      }}>
        {items.map(item => {
          const inCart = state.cart.find(c => c.id === item.id);
          return (
            <button key={item.id} onClick={() => dispatch({ type: 'OPEN_WIZARD', item, category: cat })} style={{
              textAlign: 'left',
              border: 'none', padding: 0,
              borderRadius: 28,
              background: theme.surface,
              overflow: 'hidden',
              cursor: 'pointer',
              fontFamily: 'inherit',
              position: 'relative',
              boxShadow: `0 6px 20px ${theme.shadow}`,
              transition: 'transform 120ms',
            }}>
              <ProductArt hue={item.hue || category.hue} emoji={category.emoji} size="md" />
              {item.tag && (
                <div style={{ position: 'absolute', top: 16, left: 16 }}>
                  <Tag color={item.tag === 'NOUVEAU' ? '#22c55e' : item.tag === 'XL' ? '#0ea5e9' : theme.accent}>{item.tag}</Tag>
                </div>
              )}
              {inCart && (
                <div style={{
                  position: 'absolute', top: 16, right: 16,
                  width: 44, height: 44, borderRadius: '50%',
                  background: '#22c55e', color: '#fff',
                  display: 'grid', placeItems: 'center',
                  fontSize: 24, fontWeight: 900,
                }}>{inCart.qty}</div>
              )}
              <div style={{ padding: '20px 20px 24px' }}>
                <div style={{ fontSize: 26, fontWeight: 800, color: theme.text, letterSpacing: '-0.01em', lineHeight: 1.15 }}>{item.name}</div>
                {item.desc && <div style={{ fontSize: 16, color: theme.textDim, marginTop: 6, lineHeight: 1.3 }}>{item.desc}</div>}
                <div style={{
                  marginTop: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center',
                }}>
                  <div style={{ fontSize: 30, fontWeight: 900, color: theme.accent }}>{fmt(item.price)}</div>
                  <div style={{
                    width: 48, height: 48, borderRadius: '50%',
                    background: theme.accent, color: theme.onAccent,
                    display: 'grid', placeItems: 'center',
                    fontSize: 28, fontWeight: 700,
                    boxShadow: `0 6px 16px ${theme.accent}66`,
                  }}>+</div>
                </div>
              </div>
            </button>
          );
        })}
      </div>

      <BottomBar
        theme={theme}
        cartCount={cartCount}
        cartTotal={cartTotal}
        onCartClick={() => cartCount > 0 && dispatch({ type: 'NAV', screen: 'cart' })}
      />
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// WIZARD — Configuration produit (multi-étapes)
// ═══════════════════════════════════════════════════════════════════════════
function WizardScreen({ state, dispatch, theme }) {
  const item = state.wizardItem;
  const [step, setStep] = useState(0);
  const [qty, setQty] = useState(1);
  const [choices, setChoices] = useState({});
  const steps = BURGER_WIZARD_STEPS;
  const cur = steps[step];
  const category = CATEGORIES.find(c => c.id === state.wizardCategory) || CATEGORIES[0];

  const extraPrice = Object.entries(choices).reduce((s, [stepId, sel]) => {
    const st = steps.find(s => s.id === stepId);
    if (!st) return s;
    if (st.single) {
      const opt = st.options.find(o => o.id === sel);
      return s + (opt?.price || 0);
    } else {
      return s + (sel || []).reduce((ss, id) => {
        const opt = st.options.find(o => o.id === id);
        return ss + (opt?.price || 0);
      }, 0);
    }
  }, 0);
  const unitPrice = (item?.price || 0) + extraPrice;
  const total = unitPrice * qty;

  const pickOption = (optId) => {
    if (cur.single) setChoices(c => ({ ...c, [cur.id]: optId }));
    else setChoices(c => {
      const arr = c[cur.id] || [];
      const has = arr.includes(optId);
      return { ...c, [cur.id]: has ? arr.filter(x => x !== optId) : [...arr, optId] };
    });
  };

  const canAdvance = !cur.required || (cur.single ? !!choices[cur.id] : true);

  const next = () => {
    if (step < steps.length - 1) setStep(s => s + 1);
    else {
      dispatch({ type: 'ADD_TO_CART', item: { ...item, price: unitPrice, qty, choices: {...choices}, uid: Date.now() } });
    }
  };
  const back = () => {
    if (step > 0) setStep(s => s - 1);
    else dispatch({ type: 'NAV', screen: 'menu' });
  };

  if (!item) return null;

  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', background: theme.bg }}>
      <TopBar theme={theme} orderType={state.orderType} step={step} totalSteps={steps.length} />

      {/* Hero */}
      <div style={{ position: 'relative', flex: '0 0 auto' }}>
        <ProductArt hue={item.hue || category.hue} emoji={category.emoji} size="lg" />
        <div style={{
          position: 'absolute', bottom: 0, left: 0, right: 0,
          background: `linear-gradient(to top, ${theme.bg} 0%, transparent 100%)`,
          padding: '80px 40px 20px',
        }}>
          <div style={{ fontSize: 40, fontWeight: 900, color: theme.text, letterSpacing: '-0.02em' }}>{item.name}</div>
          {item.desc && <div style={{ fontSize: 20, color: theme.textDim, marginTop: 4 }}>{item.desc}</div>}
        </div>
      </div>

      {/* Step */}
      <div style={{ flex: 1, overflowY: 'auto', padding: '24px 40px 220px' }}>
        <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 20 }}>
          <div>
            <div style={{ fontSize: 16, fontWeight: 700, color: theme.accent, letterSpacing: '0.2em', textTransform: 'uppercase' }}>
              Étape {step + 1} / {steps.length}
            </div>
            <div style={{ fontSize: 38, fontWeight: 900, color: theme.text, letterSpacing: '-0.02em', marginTop: 4 }}>{cur.title}</div>
          </div>
          {!cur.required && <Tag color={theme.textDim}>FACULTATIF</Tag>}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: cur.id === 'extras' ? '1fr 1fr' : '1fr', gap: 14 }}>
          {cur.options.map(opt => {
            const selected = cur.single ? choices[cur.id] === opt.id : (choices[cur.id] || []).includes(opt.id);
            return (
              <button key={opt.id} onClick={() => pickOption(opt.id)} style={{
                textAlign: 'left',
                padding: '22px 26px',
                borderRadius: 20,
                border: selected ? `3px solid ${theme.accent}` : `2px solid ${theme.border}`,
                background: selected ? `${theme.accent}1a` : theme.surface,
                cursor: 'pointer',
                fontFamily: 'inherit',
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                gap: 16,
                position: 'relative',
                transition: 'all 150ms',
              }}>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 26, fontWeight: 700, color: theme.text, display: 'flex', alignItems: 'center', gap: 10 }}>
                    {opt.name}
                    {opt.recommended && <span style={{ fontSize: 13, padding: '3px 10px', borderRadius: 6, background: '#22c55e', color: '#fff', fontWeight: 800, letterSpacing: '0.06em' }}>RECO</span>}
                  </div>
                  {opt.desc && <div style={{ fontSize: 17, color: theme.textDim, marginTop: 4 }}>{opt.desc}</div>}
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                  {opt.price > 0 && <div style={{ fontSize: 22, fontWeight: 800, color: theme.accent }}>+{fmt(opt.price)}</div>}
                  <div style={{
                    width: 34, height: 34,
                    borderRadius: cur.single ? '50%' : 8,
                    border: `2px solid ${selected ? theme.accent : theme.border}`,
                    background: selected ? theme.accent : 'transparent',
                    display: 'grid', placeItems: 'center',
                    color: theme.onAccent, fontSize: 20, fontWeight: 900,
                  }}>{selected && '✓'}</div>
                </div>
              </button>
            );
          })}
        </div>

        {step === steps.length - 1 && (
          <div style={{
            marginTop: 32,
            padding: '24px 26px',
            borderRadius: 20,
            background: theme.surface,
            border: `2px solid ${theme.border}`,
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          }}>
            <div style={{ fontSize: 26, fontWeight: 700, color: theme.text }}>Quantité</div>
            <QtyStepper value={qty} onChange={setQty} theme={theme} />
          </div>
        )}
      </div>

      {/* Price & nav */}
      <div style={{
        position: 'absolute', bottom: 0, left: 0, right: 0,
        padding: '20px 32px 24px',
        background: theme.barBg,
        borderTop: `1px solid ${theme.border}`,
      }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
          <div style={{ fontSize: 20, color: theme.textDim, fontWeight: 600 }}>Total</div>
          <div style={{ fontSize: 44, fontWeight: 900, color: theme.accent, letterSpacing: '-0.02em' }}>{fmt(total)}</div>
        </div>
        <div style={{ display: 'flex', gap: 16 }}>
          <button onClick={back} style={{
            flex: '0 0 auto', padding: '0 40px',
            borderRadius: 100, border: `2px solid ${theme.border}`,
            background: 'transparent', color: theme.text,
            fontSize: 28, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit',
            height: 80,
          }}>← Retour</button>
          <button onClick={next} disabled={!canAdvance} style={{
            flex: 1, padding: '0 40px', height: 80,
            borderRadius: 100, border: 'none',
            background: canAdvance ? theme.accent : theme.surface,
            color: canAdvance ? theme.onAccent : theme.textDim,
            fontSize: 30, fontWeight: 800, cursor: canAdvance ? 'pointer' : 'not-allowed', fontFamily: 'inherit',
            boxShadow: canAdvance ? `0 10px 30px ${theme.accent}66` : 'none',
          }}>
            {step < steps.length - 1 ? 'Continuer →' : `Ajouter au panier · ${fmt(total)}`}
          </button>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// POST-ADD — "Ajouté !" avec compte à rebours
// ═══════════════════════════════════════════════════════════════════════════
function PostAddScreen({ state, dispatch, theme }) {
  const [countdown, setCountdown] = useState(6);
  useEffect(() => {
    if (countdown <= 0) {
      dispatch({ type: 'NAV', screen: 'menu' });
      return;
    }
    const id = setTimeout(() => setCountdown(c => c - 1), 1000);
    return () => clearTimeout(id);
  }, [countdown]);
  const item = state.addedItem;
  const cartCount = state.cart.reduce((s, i) => s + i.qty, 0);
  const cartTotal = state.cart.reduce((s, i) => s + i.qty * i.price, 0);

  if (!item) return null;

  return (
    <div style={{ height: '100%', background: theme.bg, display: 'flex', flexDirection: 'column', alignItems: 'center', padding: 40 }}>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', width: '100%' }}>
        <div style={{
          width: 280, height: 280, borderRadius: '50%',
          background: '#22c55e22',
          display: 'grid', placeItems: 'center',
          marginBottom: 40,
          animation: 'kioskBounceIn 600ms cubic-bezier(0.34, 1.56, 0.64, 1)',
        }}>
          <div style={{
            width: 200, height: 200, borderRadius: '50%',
            background: '#22c55e',
            display: 'grid', placeItems: 'center',
            fontSize: 140, color: '#fff',
            boxShadow: '0 20px 50px #22c55e88',
          }}>✓</div>
        </div>
        <div style={{ fontSize: 80, fontWeight: 900, color: '#22c55e', letterSpacing: '-0.03em', marginBottom: 16 }}>Ajouté !</div>
        <div style={{ fontSize: 40, fontWeight: 700, color: theme.text, textAlign: 'center', maxWidth: 800 }}>{item.name}</div>
        <div style={{ fontSize: 36, fontWeight: 800, color: theme.accent, marginTop: 12 }}>×{item.qty} · {fmt(item.price * item.qty)}</div>
      </div>

      <div style={{ width: '100%', paddingBottom: 40 }}>
        <div style={{ fontSize: 22, color: theme.textDim, textAlign: 'center', marginBottom: 12 }}>
          Redirection automatique dans {countdown}s
        </div>
        <div style={{ height: 6, background: theme.border, borderRadius: 3, marginBottom: 40, overflow: 'hidden' }}>
          <div style={{ height: '100%', background: theme.accent, width: `${(countdown / 6) * 100}%`, transition: 'width 1s linear' }}/>
        </div>
        <div style={{ display: 'flex', gap: 20 }}>
          <button onClick={() => dispatch({ type: 'NAV', screen: 'menu' })} style={{
            flex: 1, height: 140, borderRadius: 24, border: `2px solid ${theme.border}`,
            background: theme.surface, color: theme.text,
            fontSize: 28, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit',
            display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 4,
          }}>
            <div style={{ fontSize: 36 }}>＋</div>
            Continuer mes achats
          </button>
          <button onClick={() => dispatch({ type: 'NAV', screen: 'cart' })} style={{
            flex: 1, height: 140, borderRadius: 24, border: 'none',
            background: theme.accent, color: theme.onAccent,
            fontSize: 28, fontWeight: 800, cursor: 'pointer', fontFamily: 'inherit',
            display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 4,
            boxShadow: `0 14px 40px ${theme.accent}66`,
          }}>
            <div style={{ fontSize: 36 }}>🛒</div>
            Mon panier · {cartCount} · {fmt(cartTotal)}
          </button>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// CART — Récapitulatif, édition quantités
// ═══════════════════════════════════════════════════════════════════════════
function CartScreen({ state, dispatch, theme }) {
  const cartTotal = state.cart.reduce((s, i) => s + i.qty * i.price, 0);
  const tax = cartTotal * 0.10 / 1.10; // TVA incluse 10%

  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', background: theme.bg }}>
      <TopBar theme={theme} orderType={state.orderType} />

      <div style={{ padding: '32px 40px 16px' }}>
        <div style={{ fontSize: 20, fontWeight: 700, color: theme.accent, letterSpacing: '0.2em', textTransform: 'uppercase' }}>Votre commande</div>
        <div style={{ fontSize: 54, fontWeight: 900, color: theme.text, letterSpacing: '-0.02em' }}>Mon panier</div>
      </div>

      <div style={{ flex: 1, overflowY: 'auto', padding: '0 40px 260px' }}>
        {state.cart.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '100px 0' }}>
            <div style={{ fontSize: 140, opacity: 0.3 }}>🛒</div>
            <div style={{ fontSize: 32, color: theme.textDim, marginTop: 20 }}>Votre panier est vide</div>
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {state.cart.map(item => {
              const category = CATEGORIES.find(c => c.id === item._cat) || CATEGORIES[0];
              return (
                <div key={item.uid} style={{
                  display: 'flex', gap: 20, alignItems: 'center',
                  padding: 20,
                  borderRadius: 24,
                  background: theme.surface,
                  border: `1px solid ${theme.border}`,
                }}>
                  <div style={{ flex: '0 0 auto', width: 120, height: 120, borderRadius: 16, overflow: 'hidden' }}>
                    <ProductArt hue={item.hue || category.hue} emoji={category.emoji} size="sm" style={{ height: 120 }} />
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 28, fontWeight: 800, color: theme.text, lineHeight: 1.15 }}>{item.name}</div>
                    {item.desc && <div style={{ fontSize: 16, color: theme.textDim, marginTop: 2 }}>{item.desc}</div>}
                    <div style={{ fontSize: 22, fontWeight: 800, color: theme.accent, marginTop: 8 }}>{fmt(item.price)}</div>
                  </div>
                  <QtyStepper
                    value={item.qty}
                    onChange={(v) => v === 0 ? dispatch({ type: 'REMOVE_FROM_CART', uid: item.uid }) : dispatch({ type: 'SET_QTY', uid: item.uid, qty: v })}
                    theme={theme}
                    min={0}
                  />
                  <div style={{ fontSize: 26, fontWeight: 800, color: theme.text, minWidth: 110, textAlign: 'right' }}>
                    {fmt(item.price * item.qty)}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Totals + CTA */}
      <div style={{
        position: 'absolute', bottom: 0, left: 0, right: 0,
        padding: '24px 32px',
        background: theme.barBg,
        borderTop: `1px solid ${theme.border}`,
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0 8px', fontSize: 18, color: theme.textDim }}>
          <span>Dont TVA (10%)</span>
          <span>{fmt(tax)}</span>
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 8px 20px', fontSize: 36, fontWeight: 900, color: theme.text }}>
          <span>Total</span>
          <span style={{ color: theme.accent }}>{fmt(cartTotal)}</span>
        </div>
        <div style={{ display: 'flex', gap: 16 }}>
          <button onClick={() => dispatch({ type: 'NAV', screen: 'menu' })} style={{
            flex: '0 0 auto', padding: '0 40px', height: 80,
            borderRadius: 100, border: `2px solid ${theme.border}`,
            background: 'transparent', color: theme.text,
            fontSize: 28, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit',
          }}>← Ajouter</button>
          <button onClick={() => dispatch({ type: 'NAV', screen: 'upsell' })} disabled={state.cart.length === 0} style={{
            flex: 1, height: 80, borderRadius: 100, border: 'none',
            background: state.cart.length ? theme.accent : theme.surface,
            color: state.cart.length ? theme.onAccent : theme.textDim,
            fontSize: 30, fontWeight: 800, cursor: state.cart.length ? 'pointer' : 'not-allowed', fontFamily: 'inherit',
            boxShadow: state.cart.length ? `0 10px 30px ${theme.accent}66` : 'none',
          }}>Valider ma commande →</button>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// UPSELL — Desserts & boissons suggérés
// ═══════════════════════════════════════════════════════════════════════════
function UpsellScreen({ state, dispatch, theme }) {
  const suggestions = [...ITEMS.desserts.slice(0, 3), ...ITEMS.drinks.slice(0, 3)];
  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', background: theme.bg }}>
      <TopBar theme={theme} orderType={state.orderType} />
      <div style={{ padding: '40px 40px 20px', textAlign: 'center' }}>
        <div style={{ fontSize: 80, marginBottom: 16 }}>🍰</div>
        <div style={{ fontSize: 48, fontWeight: 900, color: theme.text, letterSpacing: '-0.02em' }}>Et pour finir ?</div>
        <div style={{ fontSize: 26, color: theme.textDim, marginTop: 8 }}>Craquez pour une petite douceur à prix doux</div>
      </div>

      <div style={{ flex: 1, overflowY: 'auto', padding: '20px 32px 220px' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {suggestions.map(item => {
            const category = item.id.startsWith('d-') ? CATEGORIES.find(c => c.id === 'desserts') : CATEGORIES.find(c => c.id === 'drinks');
            return (
              <button key={item.id} onClick={() => dispatch({ type: 'ADD_TO_CART', item: { ...item, qty: 1, uid: Date.now() + Math.random(), _cat: category.id } })} style={{
                textAlign: 'left', border: 'none', padding: 0,
                borderRadius: 24, background: theme.surface, overflow: 'hidden',
                cursor: 'pointer', fontFamily: 'inherit',
                boxShadow: `0 6px 20px ${theme.shadow}`,
              }}>
                <ProductArt hue={item.hue || category.hue} emoji={category.emoji} size="md" />
                <div style={{ padding: 18 }}>
                  <div style={{ fontSize: 24, fontWeight: 800, color: theme.text, lineHeight: 1.15 }}>{item.name}</div>
                  {item.desc && <div style={{ fontSize: 15, color: theme.textDim, marginTop: 4 }}>{item.desc}</div>}
                  <div style={{ marginTop: 12, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <div style={{ fontSize: 26, fontWeight: 900, color: theme.accent }}>{fmt(item.price)}</div>
                    <div style={{ width: 44, height: 44, borderRadius: '50%', background: theme.accent, color: theme.onAccent, display: 'grid', placeItems: 'center', fontSize: 28, fontWeight: 700 }}>+</div>
                  </div>
                </div>
              </button>
            );
          })}
        </div>
      </div>

      <div style={{
        position: 'absolute', bottom: 0, left: 0, right: 0,
        padding: '24px 32px',
        background: theme.barBg,
        borderTop: `1px solid ${theme.border}`,
        display: 'flex', gap: 16,
      }}>
        <button onClick={() => dispatch({ type: 'NAV', screen: 'payment' })} style={{
          flex: 1, height: 90, borderRadius: 100, border: `2px solid ${theme.border}`,
          background: 'transparent', color: theme.text,
          fontSize: 26, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit',
        }}>Non merci, continuer →</button>
        <button onClick={() => dispatch({ type: 'NAV', screen: 'payment' })} style={{
          flex: 1, height: 90, borderRadius: 100, border: 'none',
          background: theme.accent, color: theme.onAccent,
          fontSize: 28, fontWeight: 800, cursor: 'pointer', fontFamily: 'inherit',
          boxShadow: `0 10px 30px ${theme.accent}66`,
        }}>Passer au paiement →</button>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// PAYMENT — Choix du mode de paiement
// ═══════════════════════════════════════════════════════════════════════════
function PaymentScreen({ state, dispatch, theme }) {
  const [processing, setProcessing] = useState(null);
  const cartTotal = state.cart.reduce((s, i) => s + i.qty * i.price, 0);

  const pay = (method) => {
    setProcessing(method);
    setTimeout(() => dispatch({ type: 'CONFIRM_ORDER', method }), 2200);
  };

  if (processing) {
    return (
      <div style={{ height: '100%', background: theme.bg, display: 'grid', placeItems: 'center', padding: 60 }}>
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 200, animation: 'kioskPulse 1.2s ease-in-out infinite' }}>
            {processing === 'card' ? '💳' : '💵'}
          </div>
          <div style={{ fontSize: 52, fontWeight: 900, color: theme.text, marginTop: 40, letterSpacing: '-0.02em' }}>
            {processing === 'card' ? 'Insérez votre carte' : 'Rendez-vous au comptoir'}
          </div>
          <div style={{ fontSize: 28, color: theme.textDim, marginTop: 20 }}>
            Montant : <span style={{ color: theme.accent, fontWeight: 900 }}>{fmt(cartTotal)}</span>
          </div>
          <div style={{ marginTop: 60, display: 'flex', gap: 10, justifyContent: 'center' }}>
            {[0,1,2].map(i => (
              <div key={i} style={{
                width: 16, height: 16, borderRadius: '50%',
                background: theme.accent,
                animation: `kioskDot 1.4s ease-in-out ${i * 0.2}s infinite`,
              }}/>
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', background: theme.bg }}>
      <TopBar theme={theme} orderType={state.orderType} />

      <div style={{ padding: '32px 40px 20px' }}>
        <div style={{ fontSize: 20, fontWeight: 700, color: theme.accent, letterSpacing: '0.2em', textTransform: 'uppercase' }}>Paiement</div>
        <div style={{ fontSize: 54, fontWeight: 900, color: theme.text, letterSpacing: '-0.02em' }}>Comment réglez-vous ?</div>
      </div>

      {/* Mini-récap */}
      <div style={{
        margin: '0 40px 24px',
        padding: 24,
        borderRadius: 24,
        background: theme.surface,
        border: `1px solid ${theme.border}`,
      }}>
        <div style={{ fontSize: 20, color: theme.textDim, fontWeight: 700, marginBottom: 12 }}>RÉCAPITULATIF</div>
        {state.cart.map(i => (
          <div key={i.uid} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: 20, color: theme.text }}>
            <span>{i.qty}× {i.name}</span>
            <span style={{ fontWeight: 700 }}>{fmt(i.price * i.qty)}</span>
          </div>
        ))}
        <div style={{ height: 1, background: theme.border, margin: '12px 0' }}/>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 34, fontWeight: 900, color: theme.text }}>
          <span>Total TTC</span>
          <span style={{ color: theme.accent }}>{fmt(cartTotal)}</span>
        </div>
      </div>

      <div style={{ flex: 1, padding: '0 40px 40px', display: 'flex', flexDirection: 'column', gap: 20 }}>
        {[
          { id: 'card', icon: '💳', title: 'Carte bancaire', sub: 'CB, sans contact, Apple/Google Pay', color: '#0ea5e9' },
          { id: 'cash', icon: '💵', title: 'Espèces au comptoir', sub: 'Payez au comptoir avant récupération', color: '#22c55e' },
          { id: 'resto', icon: '🎫', title: 'Ticket restaurant', sub: 'Dématérialisé ou papier', color: '#a855f7' },
        ].map(m => (
          <button key={m.id} onClick={() => pay(m.id)} style={{
            padding: 28, borderRadius: 28,
            border: `2px solid ${m.color}44`,
            background: `${m.color}12`,
            cursor: 'pointer', fontFamily: 'inherit',
            display: 'flex', alignItems: 'center', gap: 24,
            textAlign: 'left',
          }}>
            <div style={{
              width: 100, height: 100, borderRadius: 24,
              background: `${m.color}22`, display: 'grid', placeItems: 'center',
              fontSize: 56,
            }}>{m.icon}</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 34, fontWeight: 900, color: theme.text, letterSpacing: '-0.01em' }}>{m.title}</div>
              <div style={{ fontSize: 20, color: theme.textDim, marginTop: 4 }}>{m.sub}</div>
            </div>
            <div style={{ fontSize: 32, color: m.color }}>→</div>
          </button>
        ))}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// CONFIRM — Numéro de commande + call-out
// ═══════════════════════════════════════════════════════════════════════════
function ConfirmScreen({ state, dispatch, theme }) {
  const [countdown, setCountdown] = useState(10);
  useEffect(() => {
    if (countdown <= 0) { dispatch({ type: 'RESET' }); return; }
    const id = setTimeout(() => setCountdown(c => c - 1), 1000);
    return () => clearTimeout(id);
  }, [countdown]);

  return (
    <div style={{
      height: '100%',
      background: `radial-gradient(circle at 50% 30%, oklch(40% 0.2 140) 0%, ${theme.bg} 70%)`,
      display: 'flex', flexDirection: 'column', alignItems: 'center',
      padding: 40,
    }}>
      <div style={{ flex: 1, width: '100%', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
        <div style={{
          width: 240, height: 240, borderRadius: '50%',
          background: '#22c55e',
          display: 'grid', placeItems: 'center',
          fontSize: 160, color: '#fff',
          animation: 'kioskBounceIn 700ms cubic-bezier(0.34, 1.56, 0.64, 1)',
          boxShadow: '0 30px 80px #22c55e88',
        }}>✓</div>

        <div style={{ fontSize: 68, fontWeight: 900, color: theme.text, letterSpacing: '-0.03em', marginTop: 40 }}>Merci !</div>
        <div style={{ fontSize: 28, color: theme.textDim, marginTop: 12, textAlign: 'center' }}>
          Votre commande a bien été enregistrée
        </div>

        <div style={{
          marginTop: 60, padding: '40px 80px',
          borderRadius: 36,
          background: theme.surface,
          border: `3px dashed ${theme.accent}`,
          textAlign: 'center',
        }}>
          <div style={{ fontSize: 20, fontWeight: 700, color: theme.textDim, letterSpacing: '0.3em' }}>VOTRE NUMÉRO</div>
          <div style={{ fontSize: 220, fontWeight: 900, color: theme.accent, letterSpacing: '-0.05em', lineHeight: 1, marginTop: 12 }}>
            {state.orderId}
          </div>
        </div>

        <div style={{ marginTop: 40, fontSize: 30, color: theme.text, textAlign: 'center', maxWidth: 720, lineHeight: 1.4 }}>
          {state.orderType === 'dine_in'
            ? <>Présentez ce numéro au comptoir pour récupérer votre commande.<br/><span style={{ color: theme.textDim, fontSize: 24 }}>Temps estimé : 8-12 min</span></>
            : <>Présentez ce numéro au comptoir pour récupérer votre sac.<br/><span style={{ color: theme.textDim, fontSize: 24 }}>Prêt dans 6-10 min</span></>
          }
        </div>
      </div>

      <div style={{ paddingBottom: 20, textAlign: 'center' }}>
        <button onClick={() => dispatch({ type: 'RESET' })} style={{
          padding: '24px 48px', borderRadius: 100, border: `2px solid ${theme.border}`,
          background: theme.surface, color: theme.text,
          fontSize: 24, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit',
        }}>Nouvelle commande ({countdown}s)</button>
      </div>
    </div>
  );
}

Object.assign(window, {
  AttractScreen, OrderTypeScreen, MenuScreen, WizardScreen,
  PostAddScreen, CartScreen, UpsellScreen, PaymentScreen, ConfirmScreen,
});
