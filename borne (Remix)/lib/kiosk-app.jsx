// App principale — routeur + state + 2 directions de thème + Tweaks

const SCREENS = ['attract', 'orderType', 'menu', 'wizard', 'postAdd', 'cart', 'upsell', 'payment', 'confirm'];

// 2 thèmes contrastés
const THEMES = {
  dark: {
    name: 'Dark · Premium',
    bg: '#0d0d10',
    topBarBg: '#0d0d10',
    barBg: 'rgba(18,18,22,0.98)',
    surface: '#1a1a20',
    chip: '#1a1a20',
    text: '#f6f4ef',
    textDim: '#8d8a84',
    border: '#26262c',
    shadow: 'rgba(0,0,0,0.5)',
  },
  warm: {
    name: 'Warm · Appétissant',
    bg: '#fff8f1',
    topBarBg: '#fffaf3',
    barBg: 'rgba(255,250,243,0.98)',
    surface: '#ffffff',
    chip: '#fff1e3',
    text: '#1a0f08',
    textDim: '#8a6f5c',
    border: '#f0e3d1',
    shadow: 'rgba(168,88,32,0.14)',
  },
};

// Accent colors
const ACCENTS = [
  { id: 'red',    name: 'Rouge braise',    color: '#e53935', onAccent: '#fff' },
  { id: 'orange', name: 'Orange grill',    color: '#f57c00', onAccent: '#fff' },
  { id: 'amber',  name: 'Ambre',           color: '#f5a524', onAccent: '#1a0f08' },
  { id: 'green',  name: 'Vert frais',      color: '#2fa85a', onAccent: '#fff' },
  { id: 'purple', name: 'Violet',          color: '#7c3aed', onAccent: '#fff' },
  { id: 'blue',   name: 'Bleu',            color: '#0ea5e9', onAccent: '#fff' },
];

function makeTheme(mode, accentId) {
  const base = THEMES[mode];
  const a = ACCENTS.find(x => x.id === accentId) || ACCENTS[0];
  return { ...base, accent: a.color, onAccent: a.onAccent };
}

// ── Reducer ─────────────────────────────────────────────────────────────────
function reducer(state, action) {
  switch (action.type) {
    case 'NAV':
      return { ...state, screen: action.screen };
    case 'SET_ORDER_TYPE':
      return { ...state, orderType: action.orderType, screen: 'menu' };
    case 'OPEN_WIZARD':
      return { ...state, screen: 'wizard', wizardItem: action.item, wizardCategory: action.category };
    case 'ADD_TO_CART': {
      const it = action.item;
      const category = state.wizardCategory || it._cat || 'menus';
      const newCart = [...state.cart, { ...it, _cat: category }];
      return { ...state, cart: newCart, addedItem: it, screen: 'postAdd', wizardItem: null };
    }
    case 'REMOVE_FROM_CART':
      return { ...state, cart: state.cart.filter(i => i.uid !== action.uid) };
    case 'SET_QTY':
      return { ...state, cart: state.cart.map(i => i.uid === action.uid ? { ...i, qty: action.qty } : i) };
    case 'CONFIRM_ORDER':
      return { ...state, screen: 'confirm', orderId: String(Math.floor(Math.random() * 900) + 100), paymentMethod: action.method };
    case 'RESET':
      return { screen: 'attract', cart: [], orderType: null, orderId: null, wizardItem: null, addedItem: null };
    default:
      return state;
  }
}

const INITIAL_STATE = { screen: 'attract', cart: [], orderType: null, orderId: null, wizardItem: null, addedItem: null };

// ── Kiosk : une seule borne avec state+router ───────────────────────────────
function Kiosk({ themeMode, accentId, label, jumpTo }) {
  const [state, dispatch] = React.useReducer(reducer, INITIAL_STATE);
  const theme = useMemo(() => makeTheme(themeMode, accentId), [themeMode, accentId]);

  // Jump to a particular screen via prop (for Tweaks scene jumper)
  useEffect(() => {
    if (jumpTo && jumpTo.screen && jumpTo.ts) {
      // Seed cart with sample items so downstream screens have content
      if (['cart', 'upsell', 'payment', 'confirm', 'postAdd'].includes(jumpTo.screen) && state.cart.length === 0) {
        const sample = [
          { ...window.KIOSK_DATA.ITEMS.menus[0], qty: 1, uid: 1, _cat: 'menus' },
          { ...window.KIOSK_DATA.ITEMS.burgers[1], qty: 2, uid: 2, _cat: 'burgers' },
          { ...window.KIOSK_DATA.ITEMS.sides[0], qty: 1, uid: 3, _cat: 'sides' },
        ];
        sample.forEach(it => dispatch({ type: 'ADD_TO_CART', item: it }));
      }
      if (jumpTo.screen === 'wizard' && !state.wizardItem) {
        dispatch({ type: 'OPEN_WIZARD', item: window.KIOSK_DATA.ITEMS.burgers[1], category: 'burgers' });
        return;
      }
      if (jumpTo.screen === 'postAdd' && !state.addedItem) {
        // quick-seed addedItem via dispatch route
      }
      // orderType required for menu-and-onward
      setTimeout(() => {
        if (!state.orderType && jumpTo.screen !== 'attract' && jumpTo.screen !== 'orderType') {
          dispatch({ type: 'SET_ORDER_TYPE', orderType: 'dine_in' });
          setTimeout(() => dispatch({ type: 'NAV', screen: jumpTo.screen }), 30);
        } else {
          dispatch({ type: 'NAV', screen: jumpTo.screen });
        }
      }, 20);
    }
  }, [jumpTo?.ts]);

  const screenMap = {
    attract: AttractScreen,
    orderType: OrderTypeScreen,
    menu: MenuScreen,
    wizard: WizardScreen,
    postAdd: PostAddScreen,
    cart: CartScreen,
    upsell: UpsellScreen,
    payment: PaymentScreen,
    confirm: ConfirmScreen,
  };
  const Cur = screenMap[state.screen] || AttractScreen;

  return (
    <div data-screen-label={`${label} — ${state.screen}`} style={{
      width: 1080, height: 1920,
      position: 'relative',
      overflow: 'hidden',
      borderRadius: 0,
      background: theme.bg,
      fontFamily: "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif",
      color: theme.text,
    }}>
      <Cur state={state} dispatch={dispatch} theme={theme} />
    </div>
  );
}

// ── Borne device frame (silver bezel w/ stand silhouette) ────────────────────
function KioskFrame({ label, themeMode, children }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 20 }}>
      <div style={{ textAlign: 'center' }}>
        <div style={{ fontSize: 14, color: '#9ca3af', letterSpacing: '0.3em', textTransform: 'uppercase', fontWeight: 700 }}>
          Direction {label}
        </div>
        <div style={{ fontSize: 32, fontWeight: 800, color: '#fff', letterSpacing: '-0.02em', marginTop: 4 }}>
          {themeMode === 'dark' ? 'Dark · Premium' : 'Warm · Appétissant'}
        </div>
      </div>
      <div style={{
        padding: 36,
        borderRadius: 56,
        background: 'linear-gradient(145deg, #2a2d33 0%, #1a1c20 50%, #2a2d33 100%)',
        boxShadow: '0 60px 120px rgba(0,0,0,0.6), inset 0 2px 0 rgba(255,255,255,0.08), inset 0 -2px 0 rgba(0,0,0,0.4)',
        position: 'relative',
      }}>
        {/* Camera / sensor bar */}
        <div style={{
          position: 'absolute', top: 14, left: '50%', transform: 'translateX(-50%)',
          width: 100, height: 8, borderRadius: 4,
          background: '#0a0a0a',
          display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 12,
        }}>
          <div style={{ width: 4, height: 4, borderRadius: '50%', background: '#333' }}/>
          <div style={{ width: 4, height: 4, borderRadius: '50%', background: '#333' }}/>
        </div>
        <div style={{
          width: 1080, height: 1920,
          borderRadius: 24,
          overflow: 'hidden',
          background: '#000',
          boxShadow: 'inset 0 0 0 2px #000, inset 0 0 24px rgba(0,0,0,0.5)',
        }}>
          {children}
        </div>
        {/* Brand plate */}
        <div style={{
          position: 'absolute', bottom: 12, left: '50%', transform: 'translateX(-50%)',
          fontSize: 11, color: '#5a5d63', letterSpacing: '0.35em', fontWeight: 700,
        }}>GRILL HOUSE · KIOSK v1</div>
      </div>
    </div>
  );
}

// ── Tweaks panel ────────────────────────────────────────────────────────────
function TweaksPanel({ tweaks, setTweaks, onJump }) {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const onMsg = (e) => {
      if (e.data?.type === '__activate_edit_mode') setOpen(true);
      if (e.data?.type === '__deactivate_edit_mode') setOpen(false);
    };
    window.addEventListener('message', onMsg);
    try { window.parent.postMessage({ type: '__edit_mode_available' }, '*'); } catch {}
    return () => window.removeEventListener('message', onMsg);
  }, []);

  if (!open) return null;

  const setKey = (k, v) => {
    setTweaks(t => ({ ...t, [k]: v }));
    try { window.parent.postMessage({ type: '__edit_mode_set_keys', edits: { [k]: v } }, '*'); } catch {}
  };

  const SCREEN_OPTIONS = [
    { id: 'attract', label: 'Attract' },
    { id: 'orderType', label: 'Sur place / Emp.' },
    { id: 'menu', label: 'Menu' },
    { id: 'wizard', label: 'Wizard' },
    { id: 'postAdd', label: 'Ajouté !' },
    { id: 'cart', label: 'Panier' },
    { id: 'upsell', label: 'Upsell dessert' },
    { id: 'payment', label: 'Paiement' },
    { id: 'confirm', label: 'Confirmation' },
  ];

  return (
    <div style={{
      position: 'fixed', right: 24, bottom: 24,
      width: 320, zIndex: 9999,
      background: 'rgba(18,18,22,0.98)',
      color: '#f6f4ef',
      border: '1px solid rgba(255,255,255,0.1)',
      borderRadius: 20,
      padding: 20,
      fontFamily: 'Inter, system-ui, sans-serif',
      boxShadow: '0 30px 80px rgba(0,0,0,0.6)',
      backdropFilter: 'blur(20px)',
    }}>
      <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: '0.2em', color: '#f6f4ef', textTransform: 'uppercase', marginBottom: 16 }}>Tweaks</div>

      <div style={{ marginBottom: 18 }}>
        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.12em', color: '#8d8a84', textTransform: 'uppercase', marginBottom: 8 }}>Couleur d'accent</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6, 1fr)', gap: 8 }}>
          {ACCENTS.map(a => (
            <button key={a.id} onClick={() => setKey('accent', a.id)} title={a.name} style={{
              aspectRatio: '1', borderRadius: 10,
              border: tweaks.accent === a.id ? '3px solid #fff' : '2px solid rgba(255,255,255,0.1)',
              background: a.color,
              cursor: 'pointer',
            }}/>
          ))}
        </div>
      </div>

      <div style={{ marginBottom: 18 }}>
        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.12em', color: '#8d8a84', textTransform: 'uppercase', marginBottom: 8 }}>Aller à l'écran</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 6 }}>
          {SCREEN_OPTIONS.map(s => (
            <button key={s.id} onClick={() => onJump(s.id)} style={{
              padding: '8px 6px', fontSize: 11, fontWeight: 600,
              border: '1px solid rgba(255,255,255,0.1)',
              background: 'rgba(255,255,255,0.04)',
              color: '#f6f4ef', borderRadius: 8, cursor: 'pointer', fontFamily: 'inherit',
            }}>{s.label}</button>
          ))}
        </div>
      </div>

      <div>
        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.12em', color: '#8d8a84', textTransform: 'uppercase', marginBottom: 8 }}>Affichage</div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, cursor: 'pointer' }}>
          <input type="checkbox" checked={tweaks.showFrame} onChange={e => setKey('showFrame', e.target.checked)} />
          Afficher le châssis borne
        </label>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, cursor: 'pointer', marginTop: 6 }}>
          <input type="checkbox" checked={tweaks.sideBySide} onChange={e => setKey('sideBySide', e.target.checked)} />
          Afficher les 2 directions côte-à-côte
        </label>
      </div>
    </div>
  );
}

// ── App ─────────────────────────────────────────────────────────────────────
function App() {
  const [tweaks, setTweaks] = useState(() => ({ ...window.TWEAK_DEFAULTS }));
  const [jumpDark, setJumpDark] = useState({ screen: null, ts: 0 });
  const [jumpWarm, setJumpWarm] = useState({ screen: null, ts: 0 });

  const onJump = (screen) => {
    const ts = Date.now();
    setJumpDark({ screen, ts });
    setJumpWarm({ screen, ts });
  };

  const kioskDark = <Kiosk themeMode="dark" accentId={tweaks.accent} label="A" jumpTo={jumpDark} />;
  const kioskWarm = <Kiosk themeMode="warm" accentId={tweaks.accent} label="B" jumpTo={jumpWarm} />;

  return (
    <>
      <div style={{
        minHeight: '100vh',
        background: 'radial-gradient(ellipse at top, #1a1c22 0%, #0a0a0c 100%)',
        padding: '60px 40px',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 40,
      }}>
        <div style={{ textAlign: 'center', maxWidth: 800 }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', letterSpacing: '0.3em', textTransform: 'uppercase' }}>FoodKing SaaS · Sprint 5</div>
          <div style={{ fontSize: 48, fontWeight: 900, color: '#fff', letterSpacing: '-0.02em', marginTop: 8, fontFamily: 'Inter, system-ui, sans-serif' }}>
            Borne libre-service — Grill House
          </div>
          <div style={{ fontSize: 16, color: '#9ca3af', marginTop: 10, fontFamily: 'Inter, system-ui, sans-serif' }}>
            Flow complet : accueil → choix commande → catalogue → wizard → panier → upsell → paiement → confirmation.
            Deux directions visuelles contrastées, même flow. Ouvrez Tweaks pour changer la couleur d'accent et sauter à n'importe quel écran.
          </div>
        </div>

        {tweaks.sideBySide ? (
          <div style={{
            display: 'flex', gap: 60, alignItems: 'flex-start',
            transform: 'scale(var(--kiosk-scale))',
            transformOrigin: 'top center',
          }}>
            {tweaks.showFrame
              ? <>
                  <KioskFrame label="A" themeMode="dark">{kioskDark}</KioskFrame>
                  <KioskFrame label="B" themeMode="warm">{kioskWarm}</KioskFrame>
                </>
              : <>
                  <div>
                    <div style={{ textAlign: 'center', marginBottom: 16, fontSize: 14, color: '#9ca3af', letterSpacing: '0.25em', fontWeight: 700 }}>DIRECTION A · DARK PREMIUM</div>
                    <div style={{ borderRadius: 24, overflow: 'hidden', boxShadow: '0 40px 100px rgba(0,0,0,0.5)' }}>{kioskDark}</div>
                  </div>
                  <div>
                    <div style={{ textAlign: 'center', marginBottom: 16, fontSize: 14, color: '#9ca3af', letterSpacing: '0.25em', fontWeight: 700 }}>DIRECTION B · WARM APPÉTISSANT</div>
                    <div style={{ borderRadius: 24, overflow: 'hidden', boxShadow: '0 40px 100px rgba(0,0,0,0.5)' }}>{kioskWarm}</div>
                  </div>
                </>
            }
          </div>
        ) : (
          <div style={{ transform: 'scale(var(--kiosk-scale-solo))', transformOrigin: 'top center' }}>
            {tweaks.showFrame ? <KioskFrame label="A" themeMode={tweaks.soloTheme || 'dark'}>{tweaks.soloTheme === 'warm' ? kioskWarm : kioskDark}</KioskFrame> : (tweaks.soloTheme === 'warm' ? kioskWarm : kioskDark)}
          </div>
        )}
      </div>
      <TweaksPanel tweaks={tweaks} setTweaks={setTweaks} onJump={onJump} />
    </>
  );
}

window.KioskApp = App;
