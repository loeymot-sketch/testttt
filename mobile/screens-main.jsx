// screens-main.jsx — Home, Menu, Item, Cart, Confirmation, Orders, Profile, Loyalty
//
// Données : alimentées par mobile/data/menu.js (window.LC.menu) qui crée les
// globals window.ITEMS / window.CATS. Si la data layer n'est pas chargée
// (mode prototype isolé), on fall-back sur un stub minimal.
const { useState: uS, useEffect: uE } = React;

if (!window.LC || !window.LC.menu) {
  console.warn('[Le Cayenne] data/menu.js non chargé — fallback stub');
  window.LC = window.LC || {};
  window.LC.menu = {
    branch: { name: 'Le Cayenne', city: 'Hénin-Beaumont', zip: '62210' },
    items: [], categories: [],
    findItem: () => null, findCategory: () => null, itemsForCategory: () => [],
    priceFor: (item) => (item && item.price) || 0,
    defaultExtraIds: () => [],
  };
  window.ITEMS = window.ITEMS || [];
  window.CATS = window.CATS || [];
}
const CATS = window.CATS;
const ITEMS = window.ITEMS;

// Tag pill
const Tag = ({ t }) => {
  const map = {
    SIGNATURE: { bg: 'var(--ink)', fg: '#FFD93D' },
    SPICY: { bg: 'var(--red)', fg: '#fff' },
    TOP: { bg: 'var(--yellow)', fg: 'var(--ink)' },
    NOUVEAU: { bg: 'var(--orange)', fg: '#fff' },
  };
  const s = map[t] || { bg: 'var(--gray-1)', fg: 'var(--ink)' };
  return <span className="lc-pill" style={{ background: s.bg, color: s.fg, padding: '4px 9px', fontSize: 9 }}>{t}</span>;
};

// [Owner re-cadrage 2026-05-11 D4] EU FIC 1169/2011 — disclosure obligation des 14 allergènes
// majeurs sur tout produit alimentaire commercialisé. Composant compact (chip) + détail au tap.
// Allergènes mappés sur emoji+label FR pour lisibilité immédiate.
const ALLERGEN_META = {
  gluten:     { icon: '🌾', label: 'Gluten' },
  lactose:    { icon: '🥛', label: 'Lactose' },
  oeuf:       { icon: '🥚', label: 'Œuf' },
  arachide:   { icon: '🥜', label: 'Arachide' },
  fruits_coque: { icon: '🌰', label: 'Fruits à coque' },
  soja:       { icon: '🫘', label: 'Soja' },
  poisson:    { icon: '🐟', label: 'Poisson' },
  crustaces:  { icon: '🦐', label: 'Crustacés' },
  mollusques: { icon: '🦑', label: 'Mollusques' },
  celeri:     { icon: '🥬', label: 'Céleri' },
  moutarde:   { icon: '🟡', label: 'Moutarde' },
  sesame:     { icon: '⚪', label: 'Sésame' },
  sulfites:   { icon: '🍇', label: 'Sulfites' },
  lupin:      { icon: '🌿', label: 'Lupin' },
};

function AllergenBadge({ allergens, size = 'sm' }) {
  if (!allergens || allergens.length === 0) return null;
  const isLg = size === 'lg';
  return (
    <div
      role="region"
      aria-label={`Allergènes : ${allergens.map(k => (ALLERGEN_META[k]?.label || k)).join(', ')}`}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: isLg ? '6px 10px' : '2px 6px', background: 'var(--cream)', borderRadius: 999, fontSize: isLg ? 11 : 9, color: 'var(--gray-4)', fontWeight: 600 }}
    >
      <span aria-hidden="true" style={{ fontSize: isLg ? 12 : 10 }}>⚠️</span>
      {allergens.slice(0, isLg ? 6 : 4).map((k, i) => {
        const m = ALLERGEN_META[k] || { icon: '•', label: k };
        return (
          <span key={k} title={m.label} aria-hidden="true" style={{ fontSize: isLg ? 14 : 11, lineHeight: 1 }}>{m.icon}</span>
        );
      })}
      {allergens.length > (isLg ? 6 : 4) && (
        <span aria-hidden="true" style={{ marginLeft: 2 }}>+{allergens.length - (isLg ? 6 : 4)}</span>
      )}
    </div>
  );
}

// HOME
function ScreenHome({ go, name = 'Ikyes' }) {
  return (
    <div data-screen-label="07 Home" style={{ position: 'absolute', inset: 0, background: '#fff', overflow: 'hidden' }}>
      <div className="lc-screen" style={{ paddingBottom: 96, paddingTop: 'calc(var(--ios-safe-top) + 8px)' }}>
        {/* header — centered logo, avatar L, bell R */}
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0 20px 2px', minHeight: 40 }}>
          <button onClick={() => go('profile')} aria-label="Profil — Ikyes B." style={{ width: 40, height: 40, borderRadius: 999, background: 'var(--orange)', border: 0, color: 'var(--ink)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 17, fontWeight: 800, cursor: 'pointer' }}>IB</button>
          <div style={{ position: 'absolute', left: '50%', top: '50%', transform: 'translate(-50%, -50%)', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 }}>
            <Logo size={14}/>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 8.5, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase', color: 'var(--gray-4)' }}>
              <span style={{ width: 5, height: 5, borderRadius: 999, background: '#22c55e', boxShadow: '0 0 6px #22c55e' }}/> Ouvert
            </span>
          </div>
          <IconBtn bg="var(--cream)" ariaLabel="Notifications" onClick={() => go('toast', { msg: 'Notifications — bientôt disponibles', kind: 'info' })}><I.Bell size={18}/></IconBtn>
        </div>

        {/* greeting */}
        <div style={{ padding: '6px 20px 14px' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9, color: 'var(--ink)' }}>{(new Date().getHours() < 20 && new Date().getHours() >= 5) ? 'Bonjour,' : 'Bonsoir,'}<br/><span style={{ color: 'var(--orange)' }}>{name}</span> !</h1>
          <p style={{ margin: '10px 0 0', color: 'var(--gray-4)', fontSize: 14 }}>Qu'est-ce qui te fait envie ce soir ?</p>
        </div>

        {/* marquee categories */}
        <Marquee items={['🔥 Fait maison', '🍔 Smash burgers', '🌮 Tacos', '🥣 Bowls', '🌯 Wraps', '🍗 Buckets']}/>

        {/* featured signature card */}
        <div style={{ padding: '20px 20px 0' }}>
          {(() => {
            // Featured card pointing to a real Le Cayenne signature item.
            const featured = window.LC.menu.findItem('tacos-xxl') || window.LC.menu.items[0];
            return (
              <div onClick={() => go('item', featured.slug)} style={{ borderRadius: 24, overflow: 'hidden', background: 'var(--yellow)', position: 'relative', height: 220, display: 'flex', cursor: 'pointer', boxShadow: '6px 6px 0 var(--ink)' }}>
                <div style={{ flex: 1, padding: 20, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                  <div>
                    <span className="lc-pill lc-pill--ink" style={{ fontSize: 9 }}>SIGNATURE</span>
                    <h3 className="lc-display" style={{ margin: '12px 0 4px', fontSize: 30, lineHeight: 0.9 }}>{featured.name.toUpperCase().split(' ').slice(0, 2).join(' ')}<br/>{featured.name.toUpperCase().split(' ').slice(2).join(' ') || ''}</h3>
                    <p style={{ margin: 0, fontSize: 11, color: 'var(--gray-4)', lineHeight: 1.4 }}>{featured.description}</p>
                  </div>
                  <button className="lc-btn lc-btn--ink" style={{ height: 40, padding: '0 16px', fontSize: 11, alignSelf: 'flex-start' }}>Commander <I.Arrow size={14} stroke="var(--orange)"/></button>
                </div>
                <div style={{ width: 150, position: 'relative' }}>
                  <Slot id={featured.thumb} h="100%" radius={0} placeholder={featured.name} src={featured.hero} alt={featured.name}/>
                  <div style={{ position: 'absolute', bottom: 14, right: 14, background: '#0A0A0A', color: '#FFD93D', fontFamily: 'var(--font-display)', fontSize: 22, padding: '6px 12px', borderRadius: 8 }}>{featured.price.toFixed(2).replace('.', ',')} €</div>
                </div>
              </div>
            );
          })()}
        </div>

        {/* CATEGORIES */}
        <div style={{ marginTop: 28 }}>
          <div className="lc-sec-title">
            <h3>Categories</h3>
            <span className="more">{CATS.length} choix</span>
          </div>
          <div style={{ padding: '0 20px', display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 10 }}>
            {CATS.slice(0, 6).map(c => (
              <div key={c.id} role="button" tabIndex={0} aria-label={`Catégorie ${c.label}`} className="lc-tap" onClick={() => go('menu')} onKeyDown={window.lcTapKey(() => go('menu'))} style={{ background: 'var(--cream)', borderRadius: 14, padding: '14px 8px', textAlign: 'center', cursor: 'pointer' }}>
                <div style={{ fontSize: 26 }}>{c.icon}</div>
                <div style={{ marginTop: 6, fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{c.label}</div>
              </div>
            ))}
          </div>
        </div>

        {/* LES ENVIES */}
        <div style={{ marginTop: 28 }}>
          <div className="lc-sec-title">
            <h3>Les envies du moment</h3>
            <button style={{ background: 'var(--cream)', border: 0, padding: '8px 14px', borderRadius: 999, fontSize: 11, fontWeight: 700 }}>Voir tout</button>
          </div>
          <div style={{ paddingLeft: 20, fontSize: 12, color: 'var(--gray-3)', marginTop: -8, marginBottom: 12 }}>Notre sélection de la semaine</div>
          <div style={{ display: 'flex', gap: 12, padding: '0 20px', overflowX: 'auto' }}>
            {ITEMS.filter(i => i.is_featured).slice(0, 4).map(it => (
              <div key={it.id} onClick={() => go('item', it.id)} style={{ flex: '0 0 160px', cursor: 'pointer' }}>
                <div style={{ position: 'relative', height: 160, borderRadius: 16, overflow: 'hidden', background: 'var(--cream)' }}>
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name}/>
                  <button style={{ position: 'absolute', top: 8, right: 8, width: 32, height: 32, borderRadius: 999, background: '#fff', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    <I.Heart size={16}/>
                  </button>
                  {it.tags[0] && <div style={{ position: 'absolute', top: 8, left: 8 }}><Tag t={it.tags[0]}/></div>}
                </div>
                <div style={{ marginTop: 8, fontSize: 13, fontWeight: 700 }}>{it.name}</div>
                <div style={{ fontSize: 13, color: 'var(--orange)', fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{it.price.toFixed(2).replace('.', ',')} €</div>
              </div>
            ))}
          </div>
        </div>

        {/* NOUVEAUTÉS */}
        <div style={{ marginTop: 28 }}>
          <div className="lc-sec-title">
            <h3>Nouveautés</h3>
            <span className="more">3 plats</span>
          </div>
          <div style={{ padding: '0 20px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            {ITEMS.filter(i => (i.tags || []).includes('TOP')).slice(0, 4).map(it => (
              <div key={it.id} onClick={() => go('item', it.id)} style={{ cursor: 'pointer' }}>
                <div style={{ position: 'relative', aspectRatio: '1/1', borderRadius: 14, overflow: 'hidden', background: 'var(--ink)' }}>
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name}/>
                  <div style={{ position: 'absolute', bottom: 8, left: 8 }}><Tag t="TOP"/></div>
                </div>
                <div style={{ marginTop: 6, fontSize: 12, fontWeight: 700 }}>{it.name}</div>
                <div style={{ fontSize: 12, color: 'var(--orange)', fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{it.price.toFixed(2).replace('.', ',')} €</div>
              </div>
            ))}
          </div>
        </div>

        {/* RESTAURANT INFO */}
        <div style={{ margin: '28px 20px 12px', background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 22, position: 'relative', overflow: 'hidden' }}>
          <div style={{ position: 'absolute', top: -10, right: -10, width: 120, height: 120, opacity: 0.08 }}>
            <I.Pepper size={120} stroke="#FF5A1F"/>
          </div>
          <div style={{ width: 32, height: 4, background: 'var(--orange)', marginBottom: 12 }}/>
          <h3 className="lc-display" style={{ margin: 0, fontSize: 26, color: 'var(--yellow)' }}>LE CAYENNE<br/>HÉNIN-BEAUMONT</h3>
          <p style={{ margin: '12px 0 0', fontSize: 13, lineHeight: 1.5, color: 'rgba(255,255,255,0.75)' }}>Abdoullah en cuisine, fait maison chaque jour. Smash burgers, bowls, tacos — du peuple, pour le peuple.</p>
          <div style={{ marginTop: 18, paddingTop: 14, borderTop: '1px solid rgba(255,255,255,0.12)', display: 'flex', justifyContent: 'space-between', fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>
            <span>Ouvert 11h — 00h</span>
            <span style={{ color: 'var(--orange)' }}>06 51 30 XX XX</span>
          </div>
        </div>
      </div>
    </div>
  );
}

// MENU
function ScreenMenu({ go, cart, addToCart }) {
  const [filter, setFilter] = uS('all');
  const filtered = filter === 'all' ? ITEMS : ITEMS.filter(i => i.cat === filter);
  return (
    <div data-screen-label="08 Menu" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 160 }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', padding: '16px 20px 6px' }}>
          <div>
            <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Menu</h1>
            <div style={{ fontSize: 12, color: 'var(--gray-3)', marginTop: 4 }}>{CATS.length} catégories · {ITEMS.length} produits</div>
          </div>
          <IconBtn bg="var(--cream)" ariaLabel="Rechercher dans le menu" onClick={() => go('toast', { msg: 'Recherche — bientôt disponible', kind: 'info' })}><I.Search size={18}/></IconBtn>
        </div>
        {/* filter chips */}
        <div style={{ display: 'flex', gap: 8, padding: '12px 20px', overflowX: 'auto' }}>
          {[{ id: 'all', label: 'Tout' }, ...CATS].map(c => {
            const active = filter === c.id;
            return (
              <button key={c.id} onClick={() => setFilter(c.id)} aria-pressed={active} style={{ flexShrink: 0, padding: '10px 16px', borderRadius: 999, border: active ? '0' : '1.5px solid var(--gray-2)', background: active ? 'var(--ink)' : '#fff', color: active ? 'var(--yellow)' : 'var(--ink)', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer' }}>
                {c.icon ? `${c.icon} ` : ''}{c.label}
              </button>
            );
          })}
        </div>
        {/* category sections */}
        {CATS.filter(c => filter === 'all' || filter === c.id).map(cat => {
          const items = ITEMS.filter(i => i.cat === cat.id);
          if (!items.length) return null;
          return (
            <div key={cat.id} style={{ marginTop: 18 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', padding: '0 20px', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 24 }}>{cat.icon} {cat.label}</h3>
                <span style={{ fontSize: 11, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>{items.length} créations</span>
              </div>
              <div style={{ padding: '0 20px', display: 'grid', gap: 10 }}>
                {items.map(it => (
                  <div key={it.id} role="button" tabIndex={0} aria-label={`Voir ${it.name} — ${it.price.toFixed(2).replace('.', ',')} €`} className="lc-tap" onClick={() => go('item', it.id)} onKeyDown={window.lcTapKey(() => go('item', it.id))} style={{ display: 'flex', gap: 14, padding: 12, background: 'var(--cream)', borderRadius: 16, cursor: 'pointer', alignItems: 'center' }}>
                    <div style={{ width: 84, height: 84, borderRadius: 12, overflow: 'hidden', flexShrink: 0, background: '#0A0A0A' }}>
                      <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name}/>
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', gap: 6, marginBottom: 4, alignItems: 'center', flexWrap: 'wrap' }}>
                        {it.tags.slice(0,2).map(t => <Tag key={t} t={t}/>)}
                        {/* [D4 EU FIC 1169/2011] Allergens chip — disclosure légale obligatoire */}
                        <AllergenBadge allergens={it.allergens} size="sm"/>
                      </div>
                      <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.2 }}>{it.name}</div>
                      {/* [test-e2e fix B-004 round-2 2026-05-11] CSS line-clamp 2 + tooltip — no more hard viewport clip */}
                      <div title={it.desc} style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2, lineHeight: 1.35, overflow: 'hidden', textOverflow: 'ellipsis', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>{it.desc}</div>
                      <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 700, color: 'var(--orange)' }}>{it.price.toFixed(2).replace('.', ',')} €</span>
                        {/* [Owner re-cadrage 2026-05-11 D3] Quick-add "+" : si item personnalisable (viandes / sauce / suppléments / menu_addon / frites_style),
                            ouvrir le wizard au lieu d'ajouter direct au panier avec composition vide. Pour desserts/boissons/items direct, garder add direct. */}
                        {(it.viandes > 0 || it.has_sauce || it.has_supplements !== false || it.has_menu_addon || it.has_frites_style)
                          ? <button onClick={e => { e.stopPropagation(); go('item', it.id); }} aria-label={`Personnaliser ${it.name}`} style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--orange)', color: '#fff', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}><I.Arrow size={14} stroke="#fff" sw={2.4}/></button>
                          : <button onClick={e => { e.stopPropagation(); addToCart(it); }} aria-label={`Ajouter ${it.name} au panier`} style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--ink)', color: 'var(--yellow)', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}><I.Plus size={16}/></button>}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          );
        })}
      </div>
      {/* sticky cart bar */}
      {cart.length > 0 && (
        <div style={{ position: 'absolute', left: 16, right: 16, bottom: 96, zIndex: 8 }}>
          <button onClick={() => go('cart')} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 56, justifyContent: 'space-between', padding: '0 20px' }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span style={{ background: '#fff', color: 'var(--orange)', width: 24, height: 24, borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700 }}>{cart.length}</span>
              Voir le panier
            </span>
            <span>{cart.reduce((s,i) => s+i.price*i.qty, 0).toFixed(2).replace('.', ',')} €</span>
          </button>
        </div>
      )}
    </div>
  );
}

// ITEM DETAIL — flow kiosk-aligned (config/menu.php SSOT)
//
// Schéma item (cf. data/menu.js) :
//   item.viandes       (0-4)  → étape "Viandes" (pick N from MEATS list)
//   item.has_sauce     bool   → étape "Sauce" (1 gratuite parmi 15, sup 0.50€/sauce additionnelle)
//   item.has_crudites  bool   → étape "Crudités" (Salade/Tomate/Oignon toggle, default ON)
//   item.has_supplements bool → étape "Suppléments" (7 items toggleable)
//   item.has_menu_addon  bool → étape "Formule" (Menu+3€ / Frites+2€ / Boisson+2€)
//
// Validation pre-cart : viandesCount === item.viandes (si > 0).

// ScreenItem — thin wrapper delegating to the multi-page wizard
// (mobile/screens-item-steps.jsx exposes window.ScreenItemWizard).
// Refactor 2026-05-10 post-audit cross-agent. Single-scroll legacy preserved
// only as fallback if the wizard module fails to load.
function ScreenItem({ go, itemId, addToCart }) {
  if (typeof window.ScreenItemWizard === 'function') {
    return <window.ScreenItemWizard go={go} itemId={itemId} addToCart={addToCart}/>;
  }
  // Fallback : single-scroll (kept for safety if wizard module fails to load)
  const lcMenu = window.LC.menu;
  const item = lcMenu.findItem(itemId);
  if (!item) {
    return (
      <div data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff', padding: 40, paddingTop: 100, textAlign: 'center' }}>
        <h2 className="lc-display" style={{ fontSize: 26, color: 'var(--ink)' }}>Plat introuvable.</h2>
        <button onClick={() => go('back')} className="lc-btn lc-btn--ink" style={{ marginTop: 20 }}>Retour</button>
      </div>
    );
  }

  // -- State -----------------------------------------------------------------
  const [meatIds, setMeatIds] = uS([]);                      // selected meat ids (allow_repeat OK)
  const [sauceIds, setSauceIds] = uS([]);                    // selected sauce ids (1 free, +0.50€/extra)
  const [cruditeIds, setCruditeIds] = uS(lcMenu.defaultCruditeIds()); // default Salade+Tomate+Oignon ON
  const [supplementIds, setSupplementIds] = uS([]);
  const [formuleId, setFormuleId] = uS(null);
  const [qty, setQty] = uS(1);

  // -- Helpers ---------------------------------------------------------------
  const togglePush = (arr, id, max) => {
    if (arr.includes(id)) return arr.filter(x => x !== id);
    if (max && arr.length >= max) return [...arr.slice(1), id]; // FIFO eviction
    return [...arr, id];
  };
  const toggleMeat = (id) => setMeatIds(arr => togglePush(arr, id, item.viandes));
  const toggleSauce = (id) => {
    // If "Sans Sauce" picked, exclusive
    const sansSauceId = 's-sans';
    if (id === sansSauceId) return setSauceIds([sansSauceId]);
    setSauceIds(arr => {
      const next = arr.includes(id) ? arr.filter(x => x !== id) : [...arr.filter(x => x !== sansSauceId), id];
      return next;
    });
  };
  const toggleCrudite = (id) => setCruditeIds(arr => arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id]);
  const toggleSupplement = (id) => setSupplementIds(arr => arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id]);

  // -- Validation ------------------------------------------------------------
  const meatsOK = item.viandes === 0 || meatIds.length === item.viandes;
  const valid = meatsOK; // sauce/crudités/etc are optional

  // -- Pricing ---------------------------------------------------------------
  const unitPrice = lcMenu.priceFor(item, { sauceIds, supplementIds, formuleId, qty: 1 });
  const total = lcMenu.priceFor(item, { sauceIds, supplementIds, formuleId, qty });

  // -- Render ---------------------------------------------------------------
  return (
    <div data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 110, paddingTop: 0 }}>
        {/* hero photo */}
        <div style={{ position: 'relative', height: 280, background: 'var(--ink)' }}>
          <Slot id={item.thumb} h="100%" radius={0} placeholder={item.name} src={item.image} alt={item.name}/>
          <div style={{ position: 'absolute', top: 'calc(var(--ios-safe-top) - 14px)', left: 14, right: 14, display: 'flex', justifyContent: 'space-between', zIndex: 2 }}>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Retour"><I.Back size={20}/></IconBtn>
            <div style={{ display: 'flex', gap: 8 }}>
              <IconBtn bg="rgba(255,255,255,0.95)" ariaLabel="Ajouter aux favoris" onClick={() => go('toast', { msg: 'Favoris — bientôt disponibles', kind: 'info' })}><I.Heart size={20}/></IconBtn>
              <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Fermer"><I.Close size={20}/></IconBtn>
            </div>
          </div>
          <div style={{ position: 'absolute', bottom: 14, left: 14, display: 'flex', gap: 8 }}>
            <span className="lc-pill lc-pill--yellow" style={{ fontSize: 12, padding: '8px 14px' }}>{unitPrice.toFixed(2).replace('.', ',')} €</span>
          </div>
          {item.time > 0 && (
            <div style={{ position: 'absolute', bottom: 14, right: 14 }}>
              <span className="lc-pill" style={{ background: 'rgba(0,0,0,0.7)', color: '#fff', backdropFilter: 'blur(8px)', padding: '8px 12px' }}>
                <I.Clock size={12} stroke="#fff"/> {item.time} min
              </span>
            </div>
          )}
        </div>

        {/* content */}
        <div style={{ padding: '24px 20px 0' }}>
          <div style={{ display: 'flex', gap: 6, marginBottom: 10, alignItems: 'center', flexWrap: 'wrap' }}>
            {(item.tags || []).map(t => <Tag key={t} t={t}/>)}
            {item.is_halal && <span className="lc-pill" style={{ background: 'var(--green)', color: '#fff', fontSize: 9 }}>HALAL</span>}
            {item.is_vegetarian && <span className="lc-pill" style={{ background: 'var(--green)', color: '#fff', fontSize: 9 }}>VEGGIE</span>}
            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--gray-4)', display: 'flex', alignItems: 'center', gap: 4, marginLeft: 'auto' }}>
              <I.StarFilled size={14} stroke="var(--orange)"/> 4.8
            </span>
          </div>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 36, lineHeight: 0.95 }}>{item.name}</h1>
          <p style={{ marginTop: 10, fontSize: 14, lineHeight: 1.5, color: 'var(--gray-4)' }}>{item.description}</p>

          {item.time > 0 && (
            <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', background: 'var(--cream)', borderRadius: 999, fontSize: 12, fontWeight: 600 }}><I.Clock size={14}/> Prêt en {item.time} min</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', background: 'var(--cream)', borderRadius: 999, fontSize: 12, fontWeight: 600 }}><I.Store size={14}/> Retrait sur place</span>
            </div>
          )}

          {/* VIANDES — étape obligatoire si item.viandes > 0 */}
          {item.viandes > 0 && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>Choisis {item.viandes} viande{item.viandes > 1 ? 's' : ''}</h3>
                <span style={{ fontSize: 10, color: meatsOK ? 'var(--green)' : 'var(--orange)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>{meatIds.length}/{item.viandes}</span>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 }}>
                {lcMenu.meats.map(m => {
                  const on = meatIds.includes(m.id);
                  return (
                    <div key={m.id} onClick={() => toggleMeat(m.id)} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 12px', borderRadius: 12, border: on ? '2px solid var(--orange)' : '2px solid var(--gray-1)', background: on ? 'var(--orange-soft)' : 'var(--cream)', cursor: 'pointer' }}>
                      <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span>{m.emoji}</span>
                        {m.name}
                      </span>
                      <span style={{ width: 18, height: 18, borderRadius: 4, border: on ? '0' : '2px solid var(--gray-2)', background: on ? 'var(--orange)' : '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                        {on && <I.Check size={12} stroke="#fff" sw={3}/>}
                      </span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* SAUCE — étape optionnelle, 1 gratuite, sup 0.50€/sauce additionnelle */}
          {item.has_sauce && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>Sauce</h3>
                <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>1 gratuite · sup 0,50 €</span>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 6 }}>
                {lcMenu.sauces.map(s => {
                  const on = sauceIds.includes(s.id);
                  const free = sauceIds.indexOf(s.id) === 0;
                  return (
                    <div key={s.id} onClick={() => toggleSauce(s.id)} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 12px', borderRadius: 10, border: on ? '2px solid var(--orange)' : '1.5px solid var(--gray-1)', background: on ? 'var(--orange-soft)' : 'var(--cream)', cursor: 'pointer' }}>
                      <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 4 }}>
                        {s.is_spicy && <span>🌶️</span>}
                        {s.name}
                      </span>
                      {on && !free && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--orange)', fontWeight: 700 }}>+0,50€</span>}
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* CRUDITÉS — toggle 3 (default ON) */}
          {item.has_crudites && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>Crudités</h3>
                <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Tu peux retirer</span>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
                {lcMenu.crudites.map(c => {
                  const on = cruditeIds.includes(c.id);
                  return (
                    // [test-e2e fix B-005 round-2 2026-05-11] longhand to avoid React warning
                    <div key={c.id} onClick={() => toggleCrudite(c.id)} style={{ padding: '12px 8px', borderRadius: 12, border: on ? '2px solid var(--green)' : '2px solid var(--gray-2)', background: on ? '#E8F8ED' : 'var(--cream)', cursor: 'pointer', textAlign: 'center', textDecorationLine: on ? 'none' : 'line-through', textDecorationColor: 'var(--gray-3)' }}>
                      <div style={{ fontSize: 13, fontWeight: 700, color: on ? 'var(--green)' : 'var(--gray-3)' }}>
                        {on ? '✓' : '✕'} {c.name}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* SUPPLÉMENTS — tous payants */}
          {item.has_supplements !== false && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>Suppléments</h3>
                <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Optionnel</span>
              </div>
              <div style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
                {lcMenu.supplements.map(s => {
                  const on = supplementIds.includes(s.id);
                  return (
                    <div key={s.id} className="lc-toggle-row" onClick={() => toggleSupplement(s.id)}>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }}>{s.name}</div>
                        <div style={{ fontSize: 11, fontFamily: 'var(--font-mono)', color: 'var(--orange)', fontWeight: 700, marginTop: 2 }}>+ {s.price.toFixed(2).replace('.', ',')} €</div>
                      </div>
                      <div className={`lc-checkbox ${on ? 'lc-checkbox--on' : ''}`}>
                        {on && <I.Check size={12} stroke="#fff" sw={3}/>}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* FORMULE MENU — pour catégories has_menu */}
          {item.has_menu_addon && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>Faire un menu ?</h3>
                <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Optionnel</span>
              </div>
              <div style={{ display: 'grid', gap: 8 }}>
                {[{ id: null, name: 'Sans formule', price: 0, label: '—' }].concat(lcMenu.formules).map(f => {
                  const on = formuleId === f.id;
                  return (
                    <div key={f.id || 'none'} onClick={() => setFormuleId(f.id)} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 14px', borderRadius: 12, border: on ? '2px solid var(--orange)' : '1.5px solid var(--gray-1)', background: on ? 'var(--orange-soft)' : 'var(--cream)', cursor: 'pointer' }}>
                      <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--ink)' }}>{f.name}</span>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        {f.price > 0 && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--orange)', fontWeight: 700 }}>+ {f.price.toFixed(2).replace('.', ',')} €</span>}
                        <span style={{ width: 18, height: 18, borderRadius: 999, border: on ? '5px solid var(--orange)' : '2px solid var(--gray-2)', background: '#fff' }}/>
                      </span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* qty */}
          <div style={{ marginTop: 22, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', background: 'var(--ink)', borderRadius: 16 }}>
            <span style={{ color: '#fff', fontSize: 13, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Quantité</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <button onClick={() => setQty(q => Math.max(1, q-1))} style={{ width: 32, height: 32, borderRadius: 999, background: 'rgba(255,255,255,0.15)', border: 0, color: '#fff', cursor: 'pointer' }}><I.Minus size={14} stroke="#fff"/></button>
              <span style={{ color: 'var(--orange)', fontFamily: 'var(--font-display)', fontSize: 24, minWidth: 24, textAlign: 'center' }}>{qty}</span>
              <button onClick={() => setQty(q => q+1)} style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--orange)', border: 0, color: '#fff', cursor: 'pointer' }}><I.Plus size={14} stroke="#fff"/></button>
            </div>
          </div>
        </div>
      </div>

      {/* sticky CTA */}
      <div style={{ position: 'absolute', left: 16, right: 16, bottom: 24 }}>
        <button
          disabled={!valid}
          onClick={() => {
            if (!valid) return;
            // Build composition_snapshot aligned with FoodKing OrderItem schema
            const meatLabels = meatIds.map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean);
            const sauceLabels = sauceIds.map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
            const cruditeLabels = lcMenu.crudites.filter(c => cruditeIds.includes(c.id)).map(c => c.name);
            const supLabels = supplementIds.map(id => (lcMenu.supplements.find(s => s.id === id) || {}).name).filter(Boolean);
            const formuleLabel = formuleId ? (lcMenu.formules.find(f => f.id === formuleId) || {}).name : null;
            const summaryParts = [];
            if (meatLabels.length) summaryParts.push(meatLabels.join(' + '));
            if (sauceLabels.length) summaryParts.push('Sauce ' + sauceLabels.join('/'));
            if (cruditeLabels.length < 3 && item.has_crudites) summaryParts.push('Sans ' + lcMenu.crudites.filter(c => !cruditeIds.includes(c.id)).map(c => c.name.toLowerCase()).join('/'));
            if (supLabels.length) summaryParts.push('+ ' + supLabels.join(' + '));
            if (formuleLabel) summaryParts.push('🍽 ' + formuleLabel);

            const lineItem = {
              ...item,
              meatIds, meatLabels,
              sauceIds, sauceLabels,
              cruditeIds, cruditeLabels,
              supplementIds, supLabels,
              formuleId, formuleLabel,
              composition_summary: summaryParts.join(' · '),
              sups: supplementIds,            // backwards-compat with cart UI
              qty,
              unitPrice,
              lineTotal: total,
              price: unitPrice,                // override base price with computed unit price
            };
            addToCart(lineItem);
            go('cart');
          }}
          className="lc-btn"
          style={{
            background: valid ? 'var(--ink)' : 'var(--gray-2)',
            color: valid ? '#fff' : 'var(--gray-4)',
            width: '100%', height: 60,
            justifyContent: 'space-between', padding: '0 24px',
            cursor: valid ? 'pointer' : 'not-allowed',
          }}
        >
          <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <I.Bag size={18} stroke={valid ? 'var(--yellow)' : 'var(--gray-4)'}/>
            {valid ? 'Ajouter au panier' : `Choisis ${item.viandes - meatIds.length} viande${(item.viandes - meatIds.length) > 1 ? 's' : ''}`}
          </span>
          <span style={{ color: valid ? 'var(--yellow)' : 'var(--gray-4)' }}>{total.toFixed(2).replace('.', ',')} €</span>
        </button>
      </div>
    </div>
  );
}

// CART
function ScreenCart({ go, cart, setCart }) {
  // [Adversarial cluster-7 P1 fix] Promo discount wired — banner appliqué applique vraiment
  // une réduction (V0 mock : WELCOME10/CAYENNE = -10%). Avant : banner cosmetic-only,
  // user pensait avoir -10% mais payait full price → mauvaise UX + risque légal.
  const [promoCode, setPromoCode] = uS(null); // null | 'WELCOME10' | 'CAYENNE'
  const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const discount = promoCode ? Math.round(subtotal * 10) / 100 : 0; // -10%, arrondi 2 décimales
  const total = Math.max(0, subtotal - discount);
  const updateQty = (idx, d) => setCart(c => c.map((it, i) => i === idx ? { ...it, qty: Math.max(1, it.qty + d) } : it));
  const remove = (idx) => setCart(c => c.filter((_, i) => i !== idx));
  return (
    <div data-screen-label="10 Cart" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 200 }}>
        <ScreenHeader left={<IconBtn onClick={() => go('back')} ariaLabel="Retour"><I.Back size={20}/></IconBtn>} center={<div style={{ fontSize: 13, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>PANIER</div>}/>
        <div style={{ padding: '8px 20px 0' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Ta<br/>commande</h1>
          <div style={{ marginTop: 8, fontSize: 13, color: 'var(--gray-3)' }}>{cart.length} article{cart.length>1?'s':''} · prêt dans ~12 min</div>
        </div>
        {/* items */}
        <div style={{ padding: '20px 20px 0', display: 'grid', gap: 12 }}>
          {cart.length === 0 ? (
            <div style={{ padding: '40px 20px', textAlign: 'center', background: 'var(--cream)', borderRadius: 16 }}>
              <div style={{ fontSize: 32 }}>🛒</div>
              <div style={{ marginTop: 8, fontWeight: 700 }}>Ton panier est vide</div>
              <div style={{ fontSize: 12, color: 'var(--gray-3)', marginTop: 4 }}>Faim ? Va voir le menu.</div>
            </div>
          ) : cart.map((it, idx) => (
            <div key={idx} style={{ display: 'flex', gap: 12, padding: 12, background: 'var(--cream)', borderRadius: 16 }}>
              <div style={{ width: 80, height: 80, borderRadius: 12, overflow: 'hidden', flexShrink: 0, background: 'var(--ink)' }}>
                <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name}/>
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 14, fontWeight: 700 }}>{it.name}</div>
                {/* [test-e2e fix B-001/C-004 round-2 2026-05-11] render full composition_summary
                    built by buildLineItem (mobile/screens-item-steps.jsx) so the customer can
                    verify viandes / sauces / crudités / suppléments / formule on the cart line
                    before pressing "Valider ma commande". Falls back to legacy "+ N supplément(s)"
                    for direct-add items (Coca, Tiramisu) which never traverse the wizard and
                    therefore have no composition_summary. B-006 plural/singular agreement on n=1
                    is fixed at the same time. */}
                {it.composition_summary
                  ? <div title={it.composition_summary} style={{
                      fontSize: 11, color: 'var(--gray-4)', marginTop: 2,
                      display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical',
                      overflow: 'hidden', textOverflow: 'ellipsis', lineHeight: 1.4,
                    }}>{it.composition_summary}</div>
                  : ((it.sups||[]).length > 0
                      ? <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>+ {(it.sups).length} supplément{(it.sups).length > 1 ? 's' : ''}</div>
                      : null)
                }
                <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <div className="lc-stepper">
                    <button onClick={() => updateQty(idx, -1)} aria-label={`Diminuer la quantité de ${it.name}`}>−</button>
                    <span className="lc-stepper-val" aria-live="polite" aria-atomic="true">{it.qty}</span>
                    <button onClick={() => updateQty(idx, +1)} aria-label={`Augmenter la quantité de ${it.name}`}>+</button>
                  </div>
                  <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--orange)' }}>{(it.price*it.qty).toFixed(2).replace('.', ',')} €</span>
                </div>
              </div>
              <button onClick={() => remove(idx)} aria-label={`Retirer ${it.name} du panier`} style={{ background: 'transparent', border: 0, color: 'var(--gray-3)', cursor: 'pointer' }}><I.Trash size={16}/></button>
            </div>
          ))}
        </div>
        {/* loyalty banner */}
        <div style={{ margin: '20px 20px 0', padding: 16, background: 'var(--yellow)', borderRadius: 16, display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 40, height: 40, borderRadius: 10, background: 'var(--ink)', color: 'var(--orange)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><I.Gift size={20}/></div>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 13, fontWeight: 700 }}>+{Math.round(total)} pts gagnés sur cette commande</div>
            <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>Plus que 153 pts pour ton burger gratuit</div>
          </div>
        </div>
        {/* upsell */}
        <div style={{ marginTop: 24 }}>
          <div className="lc-sec-title">
            <h3>Pour accompagner ?</h3>
            <span className="more">Notre conseil</span>
          </div>
          <div style={{ display: 'flex', gap: 10, padding: '0 20px', overflowX: 'auto' }}>
            {ITEMS.filter(i => i.cat === 'sides' || i.cat === 'drinks' || i.cat === 'desserts').slice(0, 5).map(it => (
              <div key={it.id} onClick={() => go('item', it.id)} style={{ flex: '0 0 130px', background: 'var(--cream)', borderRadius: 12, padding: 8, cursor: 'pointer' }}>
                <div style={{ height: 80, borderRadius: 8, overflow: 'hidden', background: 'var(--ink)' }}>
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name}/>
                </div>
                <div style={{ marginTop: 6, fontSize: 12, fontWeight: 700, lineHeight: 1.2 }}>{it.name}</div>
                <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <span style={{ fontSize: 11, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{it.price.toFixed(2).replace('.', ',')} €</span>
                  <button onClick={(e) => { e.stopPropagation(); go('item', it.id); }} style={{ width: 26, height: 26, borderRadius: 999, background: 'var(--orange)', color: '#fff', border: 0, fontSize: 14, fontWeight: 700, cursor: 'pointer' }}>+</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
      {/* sticky checkout */}
      {cart.length > 0 && (
        <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', padding: '14px 20px 36px', borderTop: '1px solid var(--gray-1)' }}>
          {/* [D6 + Adversarial cluster-7 P1 fix] Promo code input + REAL discount mock V0 */}
          <PromoCodeRow onApply={setPromoCode}/>
          {/* Total breakdown : sous-total / réduction / TOTAL */}
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginTop: 10, marginBottom: 4 }}>
            <div>
              <div style={{ fontSize: 11, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>Total</div>
              {discount > 0 && (
                <div data-testid="cart-subtotal-strike" style={{ fontSize: 14, color: 'var(--gray-3)', textDecoration: 'line-through', fontFamily: 'var(--font-mono)' }}>{subtotal.toFixed(2).replace('.', ',')} €</div>
              )}
              <div className="lc-display" style={{ fontSize: 36, lineHeight: 1 }}>{total.toFixed(2).replace('.', ',')} €</div>
              {discount > 0 && (
                <div data-testid="cart-discount-line" aria-live="polite" style={{ fontSize: 11, color: 'var(--green, #1FA653)', fontWeight: 700, marginTop: 2 }}>
                  Économie <span data-testid="cart-discount-amount">{discount.toFixed(2).replace('.', ',')} €</span> (code {promoCode})
                </div>
              )}
            </div>
            <span style={{ fontSize: 11, color: 'var(--gray-3)' }}>TVA incluse</span>
          </div>
          <button onClick={() => go('confirm')} className="lc-btn" style={{ marginTop: 10, background: 'var(--orange)', color: '#fff', width: '100%', height: 60, justifyContent: 'space-between', padding: '0 24px' }}>
            Valider ma commande <I.Arrow size={18}/>
          </button>
        </div>
      )}
    </div>
  );
}

// CONFIRMATION
// [test-e2e fix C-001 round-2 2026-05-11] bind to cart order — receives { id, total, eta } from App
function ScreenConfirm({ go, order }) {
  // Fallback safe values if order missing (e.g. direct nav without prior pay step)
  const orderId = (order && order.id) || 'C-0000';
  const orderTotal = (order && typeof order.total === 'number') ? order.total : 0;
  const orderEta = (order && order.eta) || '—';
  return (
    <div data-screen-label="11 Confirmation" style={{ position: 'absolute', inset: 0, background: '#FFD93D', display: 'flex', flexDirection: 'column', paddingTop: 'var(--ios-safe-top)' }}>
      <ScreenHeader left={<IconBtn bg="var(--ink)" color="#fff" onClick={() => go('home')} ariaLabel="Fermer la confirmation"><I.Close size={18}/></IconBtn>} center={<Logo size={12}/>}/>
      <div style={{ flex: 1, padding: '4px 20px 20px', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
        <div style={{ textAlign: 'center' }}>
          <div style={{ width: 56, height: 56, borderRadius: 999, background: 'var(--ink)', color: 'var(--orange)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxShadow: '4px 4px 0 var(--paper)' }}>
            <I.Check size={28} sw={3}/>
          </div>
          <h1 className="lc-display" style={{ margin: '8px 0 0', fontSize: 36, lineHeight: 0.9 }}>C'est parti !</h1>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--ink)' }}>Commande <b>#{orderId}</b> envoyée</p>
        </div>
        {/* QR ticket card */}
        <div style={{ marginTop: 12, background: '#fff', borderRadius: 18, padding: 14, position: 'relative' }}>
          <div style={{ position: 'absolute', top: 44, left: -6, width: 12, height: 12, borderRadius: 999, background: 'var(--yellow)' }}/>
          <div style={{ position: 'absolute', top: 44, right: -6, width: 12, height: 12, borderRadius: 999, background: 'var(--yellow)' }}/>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
            <div className="lc-eyebrow" style={{ color: 'var(--gray-3)', fontSize: 9 }}>Ticket retrait</div>
            <div style={{ fontFamily: 'var(--font-mono)', fontSize: 9, color: 'var(--gray-3)' }}>HÉNIN-BEAUMONT · 62210</div>
          </div>
          <div style={{ borderTop: '1.2px dashed var(--gray-2)', paddingTop: 10, display: 'flex', justifyContent: 'center' }}>
            <QRMock size={150} value={`LECAY-ORDER-${orderId.replace(/^C-/, '')}`}/>
          </div>
          <div style={{ textAlign: 'center', fontFamily: 'var(--font-display)', fontSize: 24, letterSpacing: '0.1em', marginTop: 6 }}>{orderId}</div>
          <div style={{ borderTop: '1.2px dashed var(--gray-2)', marginTop: 10, paddingTop: 8, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
            <div>
              <div style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Prêt à</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18 }}>{orderEta}</div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Total</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: 'var(--orange)' }}>{orderTotal.toFixed(2).replace('.', ',')} €</div>
            </div>
          </div>
        </div>
        {/* ETA progress */}
        <div style={{ marginTop: 10, padding: 12, background: 'var(--ink)', borderRadius: 12, color: '#fff' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', marginBottom: 6 }}>
            <span>En préparation</span>
            <span style={{ color: 'var(--yellow)' }}>~12 MIN</span>
          </div>
          <div style={{ display: 'flex', gap: 3 }}>
            {[1,2,3,4].map(i => (
              <div key={i} style={{ flex: 1, height: 5, borderRadius: 999, background: i <= 2 ? 'var(--orange)' : 'rgba(255,255,255,0.15)' }}/>
            ))}
          </div>
        </div>
        <div style={{ marginTop: 'auto', display: 'flex', gap: 8, paddingTop: 10 }}>
          <button onClick={() => go('home')} style={{ flex: 1, background: 'var(--ink)', color: '#fff', border: 0, height: 50, borderRadius: 999, fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', cursor: 'pointer' }}>Accueil</button>
          <button onClick={() => go('orders')} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', flex: 1.6, height: 50, fontSize: 12 }}>Suivre →</button>
        </div>
      </div>
    </div>
  );
}

// ORDERS
// [test-e2e fix D-003 round-2 2026-05-11] tabs/cards/stats derived from
// window.LC.orders data layer — removed hardcoded {8, 184€, 4 cards}
// that drifted from underlying 5-entry history array.
// (D-004 active-card → orderDetail routing preserved from prior fix.)
function ScreenOrders({ go }) {
  const [tab, setTab] = uS('current');
  const ordersApi = (window.LC && window.LC.orders) ? window.LC.orders : { active: [], history: [] };
  const active = Array.isArray(ordersApi.active) ? ordersApi.active : [];
  const history = Array.isArray(ordersApi.history) ? ordersApi.history : [];
  const histTotal = history.reduce((s, o) => s + (Number(o.total) || 0), 0);
  const groupByDate = (ordersApi.groupByDate && typeof ordersApi.groupByDate === 'function')
    ? ordersApi.groupByDate
    : null;
  const dateLabel = (ordersApi.dateLabel && typeof ordersApi.dateLabel === 'function')
    ? ordersApi.dateLabel
    : function (iso) { return (iso || '').slice(0, 10); };
  const groups = groupByDate ? groupByDate(history) : [{ date: '', items: history }];
  const balancePts = (window.LC && window.LC.loyalty && window.LC.loyalty.account)
    ? window.LC.loyalty.account.balance
    : 0;
  const fmtEur = (n) => (Number(n) || 0).toFixed(2).replace('.', ',') + ' €';

  return (
    <div data-screen-label="12 Orders" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 100 }}>
        <div style={{ padding: '16px 20px 6px' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Commandes</h1>
        </div>
        {/* tabs — counts derived from data layer */}
        <div style={{ display: 'flex', gap: 6, padding: '14px 20px 0' }}>
          {[{ id: 'current', label: 'En cours', count: active.length }, { id: 'history', label: 'Historique', count: history.length }].map(t => {
            const isActive = tab === t.id;
            return (
              <button key={t.id} onClick={() => setTab(t.id)} style={{ padding: '10px 18px', borderRadius: 999, border: 0, background: isActive ? 'var(--ink)' : 'var(--cream)', color: isActive ? 'var(--yellow)' : 'var(--ink)', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
                {t.label}
                <span data-testid={'orders-tab-count-' + t.id} style={{ background: isActive ? 'var(--orange)' : 'var(--gray-2)', color: '#fff', fontSize: 10, padding: '2px 6px', borderRadius: 999 }}>{t.count}</span>
              </button>
            );
          })}
        </div>
        {tab === 'current' ? (
          <div style={{ padding: '20px 20px 0' }}>
            {active.length === 0 && (
              <div role="status" style={{ background: 'var(--cream)', borderRadius: 16, padding: 24, textAlign: 'center', color: 'var(--gray-4)', fontSize: 13 }}>
                Aucune commande en cours.
              </div>
            )}
            {active.map(o => {
              const summary = o.items_summary || (Array.isArray(o.items) ? o.items.map(i => i.name).join(' · ') : '');
              const statusLabel = (o.status_label || 'En préparation').toUpperCase();
              const eta = Number(o.eta_minutes) || 12;
              const progressSteps = Math.max(1, Math.round((Number(o.progress_pct) || 50) / 25));
              return (
                /* [test-e2e fix D-004 round-2 2026-05-11] active order routes to detail, not confirm */
                <div key={o.id} role="button" tabIndex={0} aria-label={`Commande ${o.id} en cours — voir détails`} className="lc-tap" onClick={() => go('orderDetail', o.id)} onKeyDown={window.lcTapKey(() => go('orderDetail', o.id))} style={{ background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 20, position: 'relative', overflow: 'hidden', cursor: 'pointer', marginBottom: 12 }}>
                  <div style={{ position: 'absolute', top: -20, right: -20, width: 140, height: 140, opacity: 0.06 }}><I.Pepper size={140} stroke="#FF5A1F"/></div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <span className="lc-pill" style={{ background: 'var(--orange)', color: '#fff' }}><span className="lc-status-dot" style={{ background: '#fff' }}/> {statusLabel}</span>
                    <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'rgba(255,255,255,0.6)' }}>#{o.id}</span>
                  </div>
                  <div className="lc-display" style={{ fontSize: 36, marginTop: 14, color: 'var(--yellow)' }}>~{eta} MIN</div>
                  <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>{summary}</div>
                  <div style={{ display: 'flex', gap: 4, marginTop: 16 }}>
                    {[1,2,3,4].map(i => (
                      <div key={i} style={{ flex: 1, height: 4, borderRadius: 999, background: i <= progressSteps ? 'var(--orange)' : 'rgba(255,255,255,0.15)' }}/>
                    ))}
                  </div>
                  <div style={{ marginTop: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 14, borderTop: '1px solid rgba(255,255,255,0.1)' }}>
                    <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--yellow)' }}>Voir le QR de retrait →</span>
                    <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 700 }}>{fmtEur(o.total)}</span>
                  </div>
                </div>
              );
            })}
          </div>
        ) : (
          <div style={{ padding: '20px 20px 0' }}>
            {/* Stats banner — derived from data layer */}
            <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
              <div style={{ flex: 1, background: 'var(--ink)', color: '#fff', borderRadius: 14, padding: '12px 14px' }}>
                <div style={{ fontSize: 10, color: 'var(--yellow)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>Commandes</div>
                <div data-testid="orders-stat-count" className="lc-display" style={{ fontSize: 26, color: '#fff' }}>{history.length}</div>
              </div>
              <div style={{ flex: 1, background: 'var(--orange)', color: '#fff', borderRadius: 14, padding: '12px 14px' }}>
                <div style={{ fontSize: 10, color: 'rgba(255,255,255,0.85)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>Dépensé</div>
                <div data-testid="orders-stat-total" className="lc-display" style={{ fontSize: 26 }}>{Math.round(histTotal)}€</div>
              </div>
              <div style={{ flex: 1, background: 'var(--yellow)', color: 'var(--ink)', borderRadius: 14, padding: '12px 14px' }}>
                <div style={{ fontSize: 10, color: 'var(--ink)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>Pts</div>
                <div data-testid="orders-stat-points" className="lc-display" style={{ fontSize: 26 }}>{balancePts}</div>
              </div>
            </div>
            {history.length === 0 && (
              <div role="status" style={{ background: 'var(--cream)', borderRadius: 16, padding: 24, textAlign: 'center', color: 'var(--gray-4)', fontSize: 13 }}>
                Aucune commande passée.
              </div>
            )}
            {groups.map((g, gi) => (
              <div key={(g.date || '') + '-' + gi} style={{ marginBottom: 16 }}>
                <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--orange)', letterSpacing: '0.22em', marginBottom: 8, paddingLeft: 4 }}>● {dateLabel(g.date)}</div>
                <div style={{ display: 'grid', gap: 8 }}>
                  {g.items.map(o => {
                    const summary = o.items_summary || (Array.isArray(o.items) ? o.items.map(i => i.name).join(' · ') : '');
                    const points = Number(o.points_earned) || Math.round(Number(o.total) || 0);
                    const statusLabel = o.status_label || 'Récupérée';
                    return (
                      <div key={o.id} data-testid={'orders-history-card-' + o.id} onClick={() => go('orderDetail', o.id)} style={{ background: 'var(--cream)', borderRadius: 14, padding: 14, position: 'relative', cursor: 'pointer' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                              <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--gray-3)' }}>#{o.id}</span>
                              <span style={{ width: 4, height: 4, borderRadius: 999, background: 'var(--gray-2)' }}/>
                              <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--green)', textTransform: 'uppercase', letterSpacing: '0.08em' }}>✓ {statusLabel}</span>
                            </div>
                            <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.3 }}>{summary}</div>
                            <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                              <span style={{ fontFamily: 'var(--font-display)', fontSize: 22, color: 'var(--orange)' }}>{fmtEur(o.total)}</span>
                              <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 600 }}>+{points} pts</span>
                            </div>
                          </div>
                          <button onClick={(e) => { e.stopPropagation(); go('menu'); }} style={{ background: 'var(--ink)', color: 'var(--yellow)', border: 0, padding: '10px 14px', borderRadius: 999, fontSize: 10, fontWeight: 700, cursor: 'pointer', textTransform: 'uppercase', letterSpacing: '0.08em', flexShrink: 0, alignSelf: 'center' }}>↻ Refaire</button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// PROFILE
function ScreenProfile({ go }) {
  return (
    <div data-screen-label="13 Profile" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 100 }}>
        <div style={{ padding: '16px 20px 0' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Profil</h1>
        </div>
        {/* user card */}
        <div style={{ padding: '20px 20px 0' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14, padding: 16, background: 'var(--cream)', borderRadius: 16 }}>
            <div style={{ width: 56, height: 56, borderRadius: 999, background: 'var(--orange)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 24 }}>IB</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 17, fontWeight: 700 }}>Ikyes B.</div>
              <div style={{ fontSize: 12, color: 'var(--gray-3)', fontFamily: 'var(--font-mono)' }}>+33 6 42 79 98 84</div>
            </div>
            <button onClick={() => go('toast', { msg: 'Édition profil — bientôt disponible', kind: 'info' })} style={{ background: 'var(--ink)', border: 0, padding: '8px 14px', borderRadius: 999, fontSize: 11, fontWeight: 700, cursor: 'pointer', color: 'var(--yellow)', letterSpacing: '0.08em' }}>MODIFIER</button>
          </div>
        </div>
        {/* loyalty preview card — bound to LC.loyalty per DEC-11 */}
        {(() => {
          const lAcc = window.LC.loyalty.account;
          const lProg = window.LC.loyalty.progressToNext(lAcc.balance);
          const lProgPct = lProg.target ? lProg.pct : 100;
          return (
            <div style={{ padding: '14px 20px 0' }}>
              <div role="button" tabIndex={0} aria-label={`Carte fidélité — ${lAcc.balance} points, voir détails`} className="lc-tap" onClick={() => go('loyalty')} onKeyDown={window.lcTapKey(() => go('loyalty'))} style={{ position: 'relative', background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 20, overflow: 'hidden', cursor: 'pointer' }}>
                <div style={{ position: 'absolute', top: -30, right: -30, width: 180, height: 180, borderRadius: 999, background: 'var(--orange)', opacity: 0.18 }}/>
                <div style={{ position: 'absolute', top: -10, right: -10, width: 100, height: 100, borderRadius: 999, background: 'var(--yellow)', opacity: 0.18 }}/>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                  <I.Gift size={18} stroke="var(--yellow)"/>
                  <span className="lc-eyebrow" style={{ color: 'var(--yellow)' }}>Carte fidélité</span>
                </div>
                <div className="lc-display" style={{ fontSize: 56, lineHeight: 0.9 }}>{lAcc.balance}<span style={{ fontSize: 18, color: 'var(--orange)' }}> PTS</span></div>
                {lProg.target && lProg.remaining > 0 ? (
                  <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>
                    Plus que <b style={{ color: 'var(--orange)' }}>{lProg.remaining} pts</b> pour <b style={{ color: 'var(--yellow)' }}>{lProg.target.name}</b> {lProg.target.icon}
                  </div>
                ) : (
                  <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>🎉 Toutes les récompenses débloquées</div>
                )}
                <div style={{ marginTop: 14, height: 6, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
                  <div style={{ width: lProgPct + '%', height: '100%', background: 'linear-gradient(90deg, var(--yellow), var(--orange))' }}/>
                </div>
                <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12, fontWeight: 700, color: 'var(--yellow)' }}>
                  <span>VOIR MON QR →</span>
                  <I.QR size={18}/>
                </div>
              </div>
            </div>
          );
        })()}
        {/* menu items */}
        <div style={{ padding: '24px 20px 0' }}>
          <div style={{ background: 'var(--cream)', borderRadius: 18, overflow: 'hidden' }}>
            {[
              { i: I.Gift, t: 'Ma carte fidélité', d: (window.LC.loyalty.account.balance + ' pts'), go: 'loyalty', accent: 'var(--orange)' },
              { i: I.Card, t: 'Moyens de paiement', d: 'Visa ····4242' },
              { i: I.Bell, t: 'Notifications', d: 'Activées' },
              { i: I.Pepper, t: 'Allergènes & préférences', d: '2 actives' },
              { i: I.Globe, t: 'Langue', d: 'Français' },
              { i: I.Phone, t: 'Nous contacter', d: '06 51 30 XX XX' },
              { i: I.Shield, t: 'CGU & Confidentialité' },
            ].map((row, i) => (
              <div key={i} role="button" tabIndex={0} aria-label={row.t + (row.d ? ' — ' + row.d : '')} className="lc-toggle-row lc-tap" style={{ cursor: 'pointer' }} onClick={() => row.go ? go(row.go) : go('toast', { msg: row.t + ' — bientôt disponible', kind: 'info' })} onKeyDown={window.lcTapKey(() => row.go ? go(row.go) : go('toast', { msg: row.t + ' — bientôt disponible', kind: 'info' }))}>
                <div style={{ width: 36, height: 36, borderRadius: 10, background: row.accent || 'var(--ink)', color: row.accent ? '#fff' : 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center', marginRight: 12 }}><row.i size={16}/></div>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 14, fontWeight: 600 }}>{row.t}</div>
                </div>
                {row.d && <div style={{ fontSize: 12, color: 'var(--gray-3)', marginRight: 10 }}>{row.d}</div>}
                <I.Chevron size={14} stroke="var(--gray-3)"/>
              </div>
            ))}
          </div>
          <button onClick={() => go('logout')} style={{ width: '100%', marginTop: 12, background: 'transparent', border: 0, padding: 16, fontSize: 13, fontWeight: 700, color: 'var(--red)', textTransform: 'uppercase', letterSpacing: '0.1em', cursor: 'pointer' }}>Se déconnecter</button>
        </div>
      </div>
    </div>
  );
}

// LOYALTY DETAIL
// LOYALTY — multi-section refactor (DEC-11 in 99_VERDICT.md §2)
// Sections : HERO (LoyaltyQR + member badge + countdown + toggle) →
// POINTS (balance + progress) → ACTIONS RAPIDES (wallet pills come in commit-4) →
// TABS (Mes points / Récompenses / Historique) → INFOS (rules + RGPD opt-out).
function ScreenLoyalty({ go }) {
  const [tab, setTab] = uS('points');
  const [qrMode, setQrMode] = uS((window.LC.storage && window.LC.storage.getQRPreference) ? window.LC.storage.getQRPreference() : 'qr');
  const [filterSource, setFilterSource] = uS('all');
  const [, force] = uS(0);

  // Re-render on data layer changes (earn / redeem / refund / consent)
  uE(() => {
    const handler = () => force(t => t + 1);
    window.addEventListener('lc:loyalty-changed', handler);
    return () => window.removeEventListener('lc:loyalty-changed', handler);
  }, []);

  // Render-counter for perf tests (Agent-6 §6.3)
  uE(() => {
    if (window.LC.dev && window.LC.dev.notifyRender) {
      window.LC.dev.notifyRender('ScreenLoyalty');
    }
  });

  // Bind to data layer — zero hardcoded values
  const account = window.LC.loyalty.account;
  const historyAll = window.LC.loyalty.history;
  const config = window.LC.loyalty.config;
  const rewards = window.LC.loyalty.rewards;
  const consent = (window.LC.storage && window.LC.storage.getConsent) ? window.LC.storage.getConsent() : 'opted_in';
  const isOptedOut = consent === 'opted_out';
  const userFirstName = (window.LC.user && window.LC.user.current && window.LC.user.current.first_name) || '';
  const userLastInit = (window.LC.user && window.LC.user.current && window.LC.user.current.last_name) || '';
  const memberDisplayName = (userFirstName + (userLastInit ? ' ' + userLastInit : '')).toUpperCase().trim();

  const balance = account.balance;
  const isEmpty = balance === 0 && (account.lifetime_earned || 0) === 0;
  const discountValue = window.LC.loyalty.pointsToDiscount(balance);
  const progress = window.LC.loyalty.progressToNext(balance);

  const onQRToggle = () => {
    const newMode = qrMode === 'qr' ? 'barcode' : 'qr';
    setQrMode(newMode);
    if (window.LC.storage && window.LC.storage.setQRPreference) {
      window.LC.storage.setQRPreference(newMode);
    }
  };

  // History filter chips
  const filteredHistory = filterSource === 'all'
    ? historyAll
    : historyAll.filter(h => {
        if (filterSource === 'mobile') {
          // Group all mobile_* variants (welcome, referral, birthday) with mobile
          return h.source_surface === 'mobile' || (h.source_surface || '').startsWith('mobile');
        }
        return h.source_surface === filterSource;
      });

  return (
    <div data-screen-label="14 Loyalty" data-testid="loyalty-screen" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 40 }}>
        {/* HERO — yellow top with QR/Barcode + member badge + countdown */}
        <div style={{ background: 'var(--yellow)', padding: '0 0 28px', position: 'relative', overflow: 'hidden' }}>
          <div style={{ position: 'absolute', inset: 0, opacity: 0.08, backgroundImage: 'radial-gradient(circle at 18% 22%, rgba(0,0,0,0.5) 0 2px, transparent 2px), radial-gradient(circle at 78% 65%, rgba(0,0,0,0.5) 0 2px, transparent 2px)', backgroundSize: '60px 60px' }}/>
          <ScreenHeader
            left={<IconBtn onClick={() => go('back')} bg="var(--ink)" color="#fff" aria-label="Retour"><I.Back size={20}/></IconBtn>}
            center={<div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>Carte fidélité</div>}
            right={<button data-testid="qr-mode-toggle" onClick={onQRToggle} aria-label={'Basculer vers ' + (qrMode === 'qr' ? 'code-barres' : 'QR')} style={{ width: 40, height: 40, borderRadius: 999, background: 'var(--ink)', color: '#fff', border: 0, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700, letterSpacing: '0.08em' }}>{qrMode === 'qr' ? 'III' : '▦'}</button>}
          />
          {isOptedOut ? (
            <div style={{ padding: '16px 24px', textAlign: 'center' }}>
              <div className="lc-display" style={{ fontSize: 24, color: 'var(--ink)', marginBottom: 6 }}>Programme désactivé</div>
              <div style={{ fontSize: 13, color: 'var(--ink)', marginBottom: 16 }}>Tu ne cumules plus de points et tes points ont été effacés (RGPD art. 17). Réactive pour t'inscrire à nouveau.</div>
              <button onClick={() => { if (window.LC.dev) window.LC.dev.setConsent('opted_in'); }} className="lc-btn lc-btn--ink" style={{ height: 48 }}>Réactiver mon compte</button>
            </div>
          ) : (
            <>
              {/* LoyaltyQR card — centered, memoized */}
              <div style={{ display: 'flex', justifyContent: 'center', padding: '8px 20px 0' }}>
                <div style={{ background: '#fff', borderRadius: 22, padding: 18, boxShadow: '0 12px 32px rgba(0,0,0,0.14)' }}>
                  <window.LoyaltyQR loyaltyCode={account.loyalty_code} memberNumber={account.member_number} name={memberDisplayName} mode={qrMode}/>
                </div>
              </div>
              <div style={{ marginTop: 14, textAlign: 'center', fontSize: 12, color: 'var(--ink)', fontWeight: 600 }}>
                Présente ce code à la caisse pour ajouter ou utiliser tes points
              </div>
            </>
          )}
        </div>

        {/* POINTS — gated by consent (S-001 P0 RGPD). When opted-out, modal
            copy promises balance erasure ; UI must reflect by hiding the card. */}
        {!isOptedOut && (
          <div style={{ margin: '-20px 20px 0', background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 22, position: 'relative', overflow: 'hidden' }}>
            <div style={{ position: 'absolute', top: -40, right: -40, width: 180, height: 180, borderRadius: 999, background: 'var(--orange)', opacity: 0.2 }}/>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <div className="lc-display" data-testid="loyalty-balance" style={{ fontSize: 64, lineHeight: 1 }}>{balance}</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: 'var(--orange)' }}>POINTS</div>
            </div>
            <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>
              Soit {discountValue.toFixed(2).replace('.', ',')} € de réduction disponible
            </div>
            {progress.target && progress.remaining > 0 && (
              <div style={{ marginTop: 16 }}>
                <div data-testid="loyalty-progress-text" style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: 'rgba(255,255,255,0.7)', marginBottom: 6 }}>
                  <span>{balance} / {progress.target.points_cost} pts</span>
                  <span style={{ color: 'var(--yellow)' }}>{progress.target.name.toUpperCase()}</span>
                </div>
                <div role="progressbar" aria-valuenow={balance} aria-valuemax={progress.target.points_cost} aria-valuetext={balance + ' sur ' + progress.target.points_cost + ' points'} style={{ height: 8, background: 'rgba(255,255,255,0.12)', borderRadius: 999, overflow: 'hidden', position: 'relative' }}>
                  <div style={{ width: progress.pct + '%', height: '100%', background: 'linear-gradient(90deg, var(--yellow), var(--orange))' }}/>
                </div>
                <div style={{ marginTop: 8, fontSize: 12, fontWeight: 600 }}>
                  Plus que <span style={{ color: 'var(--orange)', fontWeight: 800 }}>{progress.remaining} pts</span> pour <b style={{ color: 'var(--yellow)' }}>{progress.target.name}</b> {progress.target.icon}
                </div>
              </div>
            )}
            {progress.remaining === 0 && progress.target && (
              <div style={{ marginTop: 16, padding: 10, background: 'rgba(31,166,83,0.15)', borderRadius: 10, fontSize: 12, fontWeight: 700, color: 'var(--yellow)', textAlign: 'center' }}>
                🎉 Tu as débloqué toutes les récompenses
              </div>
            )}
          </div>
        )}

        {/* ACTIONS RAPIDES — Apple Wallet + Google Wallet + plastic card (DEC-10)
            Cycle C Wave 4 refactor : rdl-actions grid 3-col (Claude Design rdl-* lib).
            Apple/Google brand-compliant image badges PRESERVED inside rdl-action shell. */}
        {!isOptedOut && (
          <div className="rdl-actions">
            <button
              className="rdl-action"
              data-testid="wallet-apple-btn"
              onClick={() => go('walletApple')}
              aria-label="Ajouter à Apple Wallet"
              style={{ padding: 8 }}
            >
              <img src="uploads/add-to-apple-wallet-fr-stub.svg" alt="" height="36" style={{ display: 'block', borderRadius: 10, maxWidth: '100%' }}/>
              <div className="rdl-action-label" style={{ marginTop: 4 }}>Apple Wallet</div>
            </button>
            <button
              className="rdl-action"
              data-testid="wallet-google-btn"
              onClick={() => go('walletGoogle')}
              aria-label="Ajouter à Google Wallet"
              style={{ padding: 8 }}
            >
              <img src="uploads/add-to-google-wallet-fr-stub.svg" alt="" height="36" style={{ display: 'block', borderRadius: 10, maxWidth: '100%' }}/>
              <div className="rdl-action-label" style={{ marginTop: 4 }}>Google Wallet</div>
            </button>
            <button
              className="rdl-action"
              data-testid="link-plastic-card-btn"
              onClick={() => go('link')}
              aria-label={account.plastic_card_linked ? 'Carte plastique liée' : 'Lier carte plastique'}
            >
              <div className="rdl-action-icon" style={{ background: 'var(--orange)' }}>
                <I.Card size={18}/>
              </div>
              <div className="rdl-action-label">
                {account.plastic_card_linked ? <>Carte<br/>liée ✓</> : <>Lier carte<br/>plastique</>}
              </div>
            </button>
          </div>
        )}

        {/* TABS — Cycle C Wave 4 : rdl-tabs avec bottom indicator on active */}
        {!isOptedOut && (
          <div className="rdl-tabs" role="tablist" aria-label="Sections fidélité">
            {[{ id: 'points', label: 'Mes points' }, { id: 'rewards', label: 'Récompenses' }, { id: 'history', label: 'Historique' }].map(t => {
              const a = tab === t.id;
              return (
                <button
                  key={t.id}
                  className={'rdl-tab' + (a ? ' is-on' : '')}
                  data-testid={'loyalty-tab-' + t.id}
                  role="tab"
                  aria-selected={a}
                  aria-controls={'loyalty-tabpanel-' + t.id}
                  onClick={() => setTab(t.id)}
                >
                  {t.label}
                </button>
              );
            })}
          </div>
        )}

        {/* TAB CONTENT */}
        {!isOptedOut && (
          <div style={{ padding: '16px 20px 0' }}>
            {/* Empty state — new user 0 pts */}
            {isEmpty && tab === 'points' && (
              <div data-testid="loyalty-empty-state" style={{ padding: 24, background: 'var(--cream)', borderRadius: 16, textAlign: 'center' }}>
                <div style={{ fontSize: 40, marginBottom: 8 }}>🎉</div>
                <div className="lc-display" style={{ fontSize: 26, marginBottom: 4 }}>Bienvenue dans le club</div>
                <div style={{ fontSize: 13, color: 'var(--gray-4)', marginBottom: 16 }}>Tu n'as pas encore de points. Commande pour la 1ʳᵉ fois et reçois <b>{config.welcome_bonus} pts offerts</b>.</div>
                <button data-testid="cta-first-order" onClick={() => go('menu')} className="lc-btn lc-btn--orange" style={{ height: 48 }}>Commencer ma commande →</button>
                <div style={{ marginTop: 20, fontSize: 11, color: 'var(--gray-4)', textAlign: 'left' }}>
                  <div className="lc-eyebrow" style={{ marginBottom: 6, color: 'var(--ink)' }}>Aperçu des récompenses</div>
                  {rewards.slice(0, 3).map(r => (
                    <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--gray-1)' }}>
                      <span>{r.icon} {r.name}</span>
                      <span style={{ fontWeight: 700, color: 'var(--ink)' }}>{r.points_cost} pts</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Mes points — tier progression */}
            {tab === 'points' && !isEmpty && (
              <div id="loyalty-tabpanel-points" role="tabpanel" style={{ display: 'grid', gap: 8 }}>
                {config.tiers.map(tier => {
                  const matchingReward = rewards.find(r => r.points_cost === tier) || { name: '−' + (tier / config.redeem_ratio).toFixed(2).replace('.', ',') + ' €', icon: '💶', points_cost: tier };
                  const unlocked = balance >= tier;
                  const missing = unlocked ? 0 : tier - balance;
                  return (
                    <div key={tier} data-testid={'reward-row-' + tier} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, background: unlocked ? '#E8F8ED' : 'var(--cream)', borderRadius: 14, border: unlocked ? '1.5px solid var(--green)' : '1.5px solid transparent' }}>
                      <div style={{ width: 44, height: 44, borderRadius: 10, background: unlocked ? 'var(--green)' : 'var(--ink)', color: unlocked ? '#fff' : 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 14, flexShrink: 0 }}>{tier}</div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 13, fontWeight: 700 }}>{matchingReward.icon} {matchingReward.name}</div>
                        <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>{unlocked ? '✓ Disponible' : missing + ' pts manquants'}</div>
                      </div>
                      {unlocked && (
                        <button onClick={() => go('redeem', { reward: matchingReward.id || tier })} data-testid={'reward-redeem-btn-' + tier} style={{ background: 'var(--green)', color: '#fff', border: 0, padding: '8px 14px', borderRadius: 999, fontSize: 11, fontWeight: 700, cursor: 'pointer' }}>UTILISER</button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* Récompenses — catalog with locked state (Cycle C Wave 4 : rdl-reward design horizontal thumb+body+cta) */}
            {tab === 'rewards' && (
              <div id="loyalty-tabpanel-rewards" role="tabpanel" className="rdl-rewards" style={{ padding: 0 }}>
                {rewards.map(r => {
                  const locked = balance < r.points_cost;
                  const missing = locked ? r.points_cost - balance : 0;
                  return (
                    <div key={r.id} className={'rdl-reward' + (locked ? ' is-locked' : '')} data-testid={'reward-row-' + r.id}>
                      <div className="rdl-reward-thumb">{r.icon}</div>
                      <div className="rdl-reward-body">
                        <div className="rdl-reward-name">{r.name}</div>
                        <div className="rdl-reward-meta">
                          {locked
                            ? <span data-testid={'reward-locked-tooltip-' + r.id}>🔒 −{missing} pts manquants</span>
                            : <span>{r.points_cost} pts</span>}
                        </div>
                      </div>
                      <button
                        className={'rdl-reward-cta' + (locked ? ' rdl-reward-cta--locked' : '')}
                        data-testid={'reward-redeem-btn-' + r.id}
                        onClick={() => !locked && go('redeem', { reward: r.id })}
                        disabled={locked}
                        aria-disabled={locked}
                        aria-label={locked ? r.name + ' verrouillé, ' + missing + ' points manquants' : 'Échanger ' + r.name + ' contre ' + r.points_cost + ' points'}
                      >
                        {locked ? 'Verrouillé' : 'Échanger'}
                      </button>
                    </div>
                  );
                })}
                <div style={{ fontSize: 10, color: 'var(--gray-4)', textAlign: 'center', marginTop: 4, padding: '0 12px' }}>
                  ⓘ Récompenses indicatives — confirmation à la caisse
                </div>
              </div>
            )}

            {/* Historique — filterable + paginated */}
            {tab === 'history' && (
              <div id="loyalty-tabpanel-history" role="tabpanel">
                <div style={{ display: 'flex', gap: 6, marginBottom: 12, overflowX: 'auto' }}>
                  {[
                    { id: 'all', label: 'Tout' },
                    { id: 'mobile', label: '📱 App' },
                    { id: 'kiosk', label: '🖥 Kiosk' },
                    { id: 'pos', label: '🛍 Caisse' },
                  ].map(f => {
                    const active = filterSource === f.id;
                    return (
                      <button
                        key={f.id}
                        data-testid={'history-filter-' + f.id}
                        data-active={active}
                        onClick={() => setFilterSource(f.id)}
                        aria-pressed={active}
                        style={{ flexShrink: 0, padding: '6px 12px', borderRadius: 999, border: '1.5px solid ' + (active ? 'var(--ink)' : 'var(--gray-2)'), background: active ? 'var(--ink)' : 'transparent', color: active ? 'var(--yellow)' : 'var(--ink)', fontSize: 11, fontWeight: 700, cursor: 'pointer', textTransform: 'uppercase', letterSpacing: '0.06em' }}
                      >
                        {f.label}
                      </button>
                    );
                  })}
                </div>
                <div data-testid="history-list">
                  {filteredHistory.length === 0 && (
                    <div style={{ padding: 18, textAlign: 'center', color: 'var(--gray-4)', fontSize: 12 }}>Aucune transaction pour ce filtre</div>
                  )}
                  {filteredHistory.map(h => {
                    const positive = h.points > 0;
                    return (
                      <div key={h.id} className="rdl-hist" data-testid={'history-entry-' + h.id}>
                        <div className={'rdl-hist-dot rdl-hist-dot--' + (positive ? 'earn' : 'spend')} data-testid="history-source-icon" data-source-surface={h.source_surface || 'unknown'} aria-label={'Source : ' + (h.source_surface || 'inconnu')}/>
                        <div className="rdl-hist-body">
                          <div className="rdl-hist-title">{h.description}</div>
                          <div className="rdl-hist-date">{h.date} · {h.source_surface || '—'}</div>
                        </div>
                        <div className={'rdl-hist-pts ' + (positive ? 'earn' : 'spend')}>{positive ? '+' : ''}{h.points}</div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        )}

        {/* INFOS — rules + RGPD opt-out (modal in commit-5) */}
        {!isOptedOut && (
          <div style={{ padding: '24px 20px 0' }}>
            <div className="lc-eyebrow" style={{ color: 'var(--gray-4)', marginBottom: 8 }}>Programme fidélité</div>
            <div style={{ background: 'var(--cream)', borderRadius: 14, padding: 14, fontSize: 12, color: 'var(--ink)', marginBottom: 8 }}>
              {config.earn_ratio} pt par € dépensé · {config.redeem_ratio} pts = 1 € de réduction · Validité {config.expires_after_days} jours
            </div>
            <button
              data-testid="loyalty-opt-out-btn"
              onClick={() => go('optOut')}
              style={{ width: '100%', padding: '12px 14px', background: 'transparent', border: '1.5px solid var(--gray-2)', borderRadius: 12, fontSize: 12, fontWeight: 700, color: 'var(--gray-4)', cursor: 'pointer', letterSpacing: '0.06em', textTransform: 'uppercase' }}
            >
              Désactiver mon compte fidélité
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// [D6 + Adversarial cluster-7 P1 fix 2026-05-11] PromoCodeRow — input promo + mock V0.
// Adversarial caught : success banner was cosmetic-only, total stayed full price.
// Fix : onApply callback lifts the applied code to ScreenCart which computes -10% discount.
// Backend wireup Phase 6.C : POST /api/v1/frontend/cart/promo with code → returns discount or 422.
function PromoCodeRow({ onApply }) {
  const [code, setCode] = uS('');
  const [status, setStatus] = uS('idle'); // idle | applied | invalid
  const [appliedCode, setAppliedCode] = uS('');
  const apply = () => {
    const trimmed = code.trim().toUpperCase();
    if (!trimmed) return;
    if (trimmed === 'WELCOME10' || trimmed === 'CAYENNE') {
      setStatus('applied');
      setAppliedCode(trimmed);
      if (typeof onApply === 'function') onApply(trimmed);
    } else {
      setStatus('invalid');
      setTimeout(() => setStatus('idle'), 2200);
    }
  };
  const clear = () => {
    setCode(''); setStatus('idle'); setAppliedCode('');
    if (typeof onApply === 'function') onApply(null);
  };
  if (status === 'applied') {
    return (
      <div data-testid="cart-promo-applied" role="status" aria-live="polite" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 12px', background: 'var(--green-light, #ECFDF5)', border: '1px solid var(--green, #1FA653)', borderRadius: 10, marginBottom: 4 }}>
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--green-dark, #047857)' }}>✓ Code <code>{appliedCode}</code> appliqué</span>
        <button onClick={clear} aria-label="Retirer le code promo" style={{ background: 'transparent', border: 0, color: 'var(--gray-4)', fontSize: 11, cursor: 'pointer' }}>Retirer</button>
      </div>
    );
  }
  return (
    <div style={{ marginBottom: 4 }}>
      <div style={{ display: 'flex', gap: 8, alignItems: 'stretch' }}>
        <input
          data-testid="cart-promo-input"
          type="text"
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase().slice(0, 16))}
          placeholder="Code promo (ex. WELCOME10)"
          aria-label="Code promo"
          style={{ flex: 1, minWidth: 0, padding: '10px 12px', fontSize: 13, fontFamily: 'var(--font-mono)', border: status === 'invalid' ? '1.5px solid var(--red)' : '1.5px solid var(--gray-2)', borderRadius: 10, background: '#fff', color: 'var(--ink)', textTransform: 'uppercase', letterSpacing: '0.04em' }}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); apply(); } }}
        />
        <button onClick={apply} disabled={!code.trim()} aria-label="Appliquer le code promo" style={{ padding: '0 16px', fontSize: 12, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', background: code.trim() ? 'var(--ink)' : 'var(--gray-1)', color: code.trim() ? 'var(--yellow)' : 'var(--gray-3)', border: 0, borderRadius: 10, cursor: code.trim() ? 'pointer' : 'not-allowed' }}>Appliquer</button>
      </div>
      {status === 'invalid' && (
        <div role="alert" aria-live="assertive" style={{ marginTop: 4, fontSize: 11, color: 'var(--red)', fontWeight: 600 }}>Code invalide</div>
      )}
    </div>
  );
}

Object.assign(window, { ScreenHome, ScreenMenu, ScreenItem, ScreenCart, ScreenConfirm, ScreenOrders, ScreenProfile, ScreenLoyalty, ITEMS, CATS, AllergenBadge, PromoCodeRow });
