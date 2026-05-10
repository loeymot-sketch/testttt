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

// HOME
function ScreenHome({ go, name = 'Ikyes' }) {
  return (
    <div data-screen-label="07 Home" style={{ position: 'absolute', inset: 0, background: '#fff', overflow: 'hidden' }}>
      <div className="lc-screen" style={{ paddingBottom: 96, paddingTop: 'calc(var(--ios-safe-top) + 8px)' }}>
        {/* header — centered logo, avatar L, bell R */}
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0 20px 2px', minHeight: 40 }}>
          <button onClick={() => go('profile')} style={{ width: 40, height: 40, borderRadius: 999, background: 'var(--orange)', border: 0, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 17, cursor: 'pointer' }}>IB</button>
          <div style={{ position: 'absolute', left: '50%', top: '50%', transform: 'translate(-50%, -50%)', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 }}>
            <Logo size={14}/>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 8.5, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase', color: 'var(--gray-4)' }}>
              <span style={{ width: 5, height: 5, borderRadius: 999, background: '#22c55e', boxShadow: '0 0 6px #22c55e' }}/> Ouvert
            </span>
          </div>
          <IconBtn bg="var(--cream)"><I.Bell size={18}/></IconBtn>
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
          <div onClick={() => go('item', 'box-familiale')} style={{ borderRadius: 24, overflow: 'hidden', background: 'var(--yellow)', position: 'relative', height: 220, display: 'flex', cursor: 'pointer', boxShadow: '6px 6px 0 var(--ink)' }}>
            <div style={{ flex: 1, padding: 20, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
              <div>
                <span className="lc-pill lc-pill--ink" style={{ fontSize: 9 }}>SIGNATURE</span>
                <h3 className="lc-display" style={{ margin: '12px 0 4px', fontSize: 32, lineHeight: 0.9 }}>BOX<br/>FAMILIALE</h3>
                <p style={{ margin: 0, fontSize: 11, color: 'var(--gray-4)', lineHeight: 1.4 }}>4 smash, 5 wings, 5 tenders,<br/>frite XXL, 4 boissons</p>
              </div>
              <button className="lc-btn lc-btn--ink" style={{ height: 40, padding: '0 16px', fontSize: 11, alignSelf: 'flex-start' }}>Commander <I.Arrow size={14} stroke="var(--orange)"/></button>
            </div>
            <div style={{ width: 150, position: 'relative' }}>
              <Slot id="home-feat" h="100%" radius={0} placeholder="Box Familiale"/>
              <div style={{ position: 'absolute', bottom: 14, right: 14, background: '#0A0A0A', color: '#FFD93D', fontFamily: 'var(--font-display)', fontSize: 22, padding: '6px 12px', borderRadius: 8 }}>29,00 €</div>
            </div>
          </div>
        </div>

        {/* CATEGORIES */}
        <div style={{ marginTop: 28 }}>
          <div className="lc-sec-title">
            <h3>Categories</h3>
            <span className="more">{CATS.length} choix</span>
          </div>
          <div style={{ padding: '0 20px', display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 10 }}>
            {CATS.map(c => (
              <div key={c.id} onClick={() => go('menu')} style={{ background: 'var(--cream)', borderRadius: 14, padding: '14px 8px', textAlign: 'center', cursor: 'pointer' }}>
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
            {ITEMS.slice(0,3).map(it => (
              <div key={it.id} onClick={() => go('item', it.id)} style={{ flex: '0 0 160px', cursor: 'pointer' }}>
                <div style={{ position: 'relative', height: 160, borderRadius: 16, overflow: 'hidden', background: 'var(--cream)' }}>
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name}/>
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
            {ITEMS.slice(3,6).map(it => (
              <div key={it.id} onClick={() => go('item', it.id)} style={{ cursor: 'pointer' }}>
                <div style={{ position: 'relative', aspectRatio: '1/1', borderRadius: 14, overflow: 'hidden', background: 'var(--ink)' }}>
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name}/>
                  <div style={{ position: 'absolute', bottom: 8, left: 8 }}><Tag t="NOUVEAU"/></div>
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
          <IconBtn bg="var(--cream)"><I.Search size={18}/></IconBtn>
        </div>
        {/* filter chips */}
        <div style={{ display: 'flex', gap: 8, padding: '12px 20px', overflowX: 'auto' }}>
          {[{ id: 'all', label: 'Tout' }, ...CATS].map(c => {
            const active = filter === c.id;
            return (
              <button key={c.id} onClick={() => setFilter(c.id)} style={{ flexShrink: 0, padding: '10px 16px', borderRadius: 999, border: active ? '0' : '1.5px solid var(--gray-2)', background: active ? 'var(--ink)' : '#fff', color: active ? 'var(--yellow)' : 'var(--ink)', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer' }}>
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
                  <div key={it.id} onClick={() => go('item', it.id)} style={{ display: 'flex', gap: 14, padding: 12, background: 'var(--cream)', borderRadius: 16, cursor: 'pointer', alignItems: 'center' }}>
                    <div style={{ width: 84, height: 84, borderRadius: 12, overflow: 'hidden', flexShrink: 0, background: '#0A0A0A' }}>
                      <Slot id={it.slot} h="100%" radius={0} placeholder={it.name}/>
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', gap: 6, marginBottom: 4 }}>{it.tags.slice(0,2).map(t => <Tag key={t} t={t}/>)}</div>
                      <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.2 }}>{it.name}</div>
                      <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{it.desc}</div>
                      <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 700, color: 'var(--orange)' }}>{it.price.toFixed(2).replace('.', ',')} €</span>
                        <button onClick={e => { e.stopPropagation(); addToCart(it); }} style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--ink)', color: 'var(--yellow)', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}><I.Plus size={16}/></button>
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

// ITEM DETAIL — wizard complet (variations + addons + extras + composition steps)
//
// Schéma supporté (cf. data/menu.js + KioskMenuService backend) :
//  - item.variations[]                          taille / déclinaison (radio min=max=1)
//  - item.itemAttributes[]                      contraintes (min/max, allow_repeat)
//  - item.addons[].options[]                    choix viande / sauces (custom V0)
//  - item.extras[]                              suppléments (toggleable, groupés)
//  - item.wizard_profile.steps[]                composition box (radio par étape)
//
// Validation pre-cart : chaque step et attribute doit satisfaire min_select.

function ScreenItem({ go, itemId, addToCart }) {
  const lcMenu = window.LC.menu;
  const item = lcMenu.findItem(itemId) || lcMenu.findItem('cheese-smash') || ITEMS[0];
  if (!item) {
    return <div data-screen-label="09 Item Detail" style={{ padding: 40, textAlign: 'center' }}>Plat introuvable.</div>;
  }

  // -- State -----------------------------------------------------------------
  const variations = item.variations || [];
  const [variationId, setVariationId] = uS(variations[0] ? variations[0].id : null);

  const addonsWithOptions = (item.addons || []).filter(a => a.options && a.options.length);
  const initAddon = {};
  addonsWithOptions.forEach(a => { initAddon[a.id] = a.options[0] ? a.options[0].id : null; });
  const [addonChoices, setAddonChoices] = uS(initAddon);

  const [extraIds, setExtraIds] = uS(lcMenu.defaultExtraIds(item));

  const wizardSteps = (item.wizard_profile && item.wizard_profile.steps) || [];
  const initWizard = {};
  wizardSteps.forEach(s => {
    initWizard[s.step_key] = s.max_select === 1 ? (s.options[0] ? s.options[0].id : null) : [];
  });
  const [wizard, setWizard] = uS(initWizard);

  const [qty, setQty] = uS(1);

  // -- Helpers ---------------------------------------------------------------
  const toggleExtra = (id) => setExtraIds(arr => arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id]);

  const setWizardChoice = (stepKey, optId) => {
    const step = wizardSteps.find(s => s.step_key === stepKey);
    if (!step) return;
    setWizard(w => {
      if (step.max_select === 1) return { ...w, [stepKey]: optId };
      const cur = Array.isArray(w[stepKey]) ? w[stepKey] : [];
      return { ...w, [stepKey]: cur.includes(optId) ? cur.filter(x => x !== optId) : [...cur, optId] };
    });
  };

  // Validation: each wizard step satisfies min_select
  const wizardComplete = wizardSteps.every(s => {
    const sel = wizard[s.step_key];
    const count = Array.isArray(sel) ? sel.length : (sel ? 1 : 0);
    return count >= s.min_select;
  });

  // Group extras by group_label for nicer UI
  const extrasGrouped = {};
  (item.extras || []).forEach(e => {
    const g = e.group_label || 'Suppléments';
    extrasGrouped[g] = extrasGrouped[g] || [];
    extrasGrouped[g].push(e);
  });

  // Compute total via priceFor (V0 client-side ; en prod = PricingService backend)
  const total = lcMenu.priceFor(item, { variationId, extraIds, wizardSelections: wizard, qty });
  const unitPrice = lcMenu.priceFor(item, { variationId, extraIds, wizardSelections: wizard, qty: 1 });

  // -- Render ---------------------------------------------------------------
  return (
    <div data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 110, paddingTop: 0 }}>
        {/* hero photo */}
        <div style={{ position: 'relative', height: 280, background: 'var(--ink)' }}>
          <Slot id={item.thumb || item.slot} h="100%" radius={0} placeholder={item.name}/>
          <div style={{ position: 'absolute', top: 'calc(var(--ios-safe-top) - 14px)', left: 14, right: 14, display: 'flex', justifyContent: 'space-between', zIndex: 2 }}>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)"><I.Back size={20}/></IconBtn>
            <div style={{ display: 'flex', gap: 8 }}>
              <IconBtn bg="rgba(255,255,255,0.95)"><I.Heart size={20}/></IconBtn>
              <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)"><I.Close size={20}/></IconBtn>
            </div>
          </div>
          <div style={{ position: 'absolute', bottom: 14, left: 14, display: 'flex', gap: 8 }}>
            <span className="lc-pill lc-pill--yellow" style={{ fontSize: 12, padding: '8px 14px' }}>{unitPrice.toFixed(2).replace('.', ',')} €</span>
          </div>
          <div style={{ position: 'absolute', bottom: 14, right: 14 }}>
            <span className="lc-pill" style={{ background: 'rgba(0,0,0,0.7)', color: '#fff', backdropFilter: 'blur(8px)', padding: '8px 12px' }}>
              <I.Clock size={12} stroke="#fff"/> {item.time} min
            </span>
          </div>
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
          <p style={{ marginTop: 10, fontSize: 14, lineHeight: 1.5, color: 'var(--gray-4)' }}>{item.description || item.desc}</p>

          <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', background: 'var(--cream)', borderRadius: 999, fontSize: 12, fontWeight: 600 }}><I.Clock size={14}/> Prêt en {item.time} min</span>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', background: 'var(--cream)', borderRadius: 999, fontSize: 12, fontWeight: 600 }}><I.Store size={14}/> Retrait sur place</span>
          </div>

          {/* VARIATIONS (taille / déclinaison) */}
          {variations.length > 0 && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>{(item.itemAttributes || [])[0]?.name || 'Taille'}</h3>
                <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Obligatoire</span>
              </div>
              <div style={{ display: 'grid', gap: 8 }}>
                {variations.map(v => {
                  const on = variationId === v.id;
                  return (
                    <div key={v.id} onClick={() => setVariationId(v.id)} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 16px', borderRadius: 14, border: on ? '2px solid var(--orange)' : '2px solid var(--gray-1)', background: on ? 'var(--orange-soft)' : 'var(--cream)', cursor: 'pointer' }}>
                      <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--ink)' }}>{v.name}</span>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: on ? 'var(--orange)' : 'var(--gray-4)', fontSize: 13 }}>{v.price.toFixed(2).replace('.', ',')} €</span>
                        <span style={{ width: 20, height: 20, borderRadius: 999, border: on ? '6px solid var(--orange)' : '2px solid var(--gray-2)', background: '#fff', flexShrink: 0 }}/>
                      </span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* ADDON CHOICES (e.g. tacos viande) */}
          {addonsWithOptions.map(addon => (
            <div key={addon.id} style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 20 }}>{addon.name}</h3>
                <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Choix</span>
              </div>
              <div style={{ display: 'grid', gap: 8 }}>
                {addon.options.map(opt => {
                  const on = addonChoices[addon.id] === opt.id;
                  return (
                    <div key={opt.id} onClick={() => setAddonChoices(c => ({ ...c, [addon.id]: opt.id }))} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderRadius: 14, border: on ? '2px solid var(--orange)' : '2px solid var(--gray-1)', background: on ? 'var(--orange-soft)' : 'var(--cream)', cursor: 'pointer' }}>
                      <span style={{ fontSize: 14, fontWeight: 600, color: 'var(--ink)' }}>{opt.name}</span>
                      {opt.price > 0 ? (
                        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, fontWeight: 700, color: 'var(--orange)' }}>+ {opt.price.toFixed(2).replace('.', ',')} €</span>
                      ) : (
                        <span style={{ width: 18, height: 18, borderRadius: 999, border: on ? '5px solid var(--orange)' : '2px solid var(--gray-2)', background: '#fff' }}/>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          ))}

          {/* WIZARD STEPS (composition box) */}
          {wizardSteps.length > 0 && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 12 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 22 }}>Composition</h3>
                <span style={{ fontSize: 10, color: 'var(--orange)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>{wizardSteps.length} étapes</span>
              </div>
              <div style={{ display: 'grid', gap: 14 }}>
                {wizardSteps.map((step, idx) => {
                  const sel = wizard[step.step_key];
                  return (
                    <div key={step.step_key} style={{ background: 'var(--cream)', borderRadius: 16, padding: 14, border: '1.5px solid var(--gray-1)' }}>
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <div style={{ width: 24, height: 24, borderRadius: 999, background: 'var(--ink)', color: 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700 }}>{idx + 1}</div>
                          <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--ink)' }}>{step.label}</span>
                        </div>
                        <span style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em' }}>{step.min_select}/{step.max_select === step.min_select ? step.min_select : step.max_select}</span>
                      </div>
                      <div style={{ display: 'grid', gap: 6 }}>
                        {step.options.map(opt => {
                          const on = step.max_select === 1 ? sel === opt.id : (Array.isArray(sel) && sel.includes(opt.id));
                          return (
                            <div key={opt.id} onClick={() => setWizardChoice(step.step_key, opt.id)} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 12px', borderRadius: 10, background: on ? 'var(--ink)' : '#fff', color: on ? 'var(--yellow)' : 'var(--ink)', cursor: 'pointer' }}>
                              <span style={{ fontSize: 13, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8 }}>
                                {opt.kiosk_emoji && <span>{opt.kiosk_emoji}</span>}
                                {opt.name}
                              </span>
                              <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                {opt.price > 0 && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, fontWeight: 700, color: on ? 'var(--orange)' : 'var(--orange)' }}>+ {opt.price.toFixed(2).replace('.', ',')} €</span>}
                                <span style={{ width: 16, height: 16, borderRadius: step.max_select === 1 ? 999 : 4, border: on ? '0' : '2px solid var(--gray-2)', background: on ? 'var(--orange)' : '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                  {on && <I.Check size={10} stroke="#fff" sw={3}/>}
                                </span>
                              </span>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* EXTRAS (suppléments groupés par group_label) */}
          {Object.keys(extrasGrouped).length > 0 && (
            <div style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 12 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 22 }}>Suppléments</h3>
                <span style={{ fontSize: 11, color: 'var(--gray-3)', fontWeight: 600 }}>Optionnel</span>
              </div>
              {Object.keys(extrasGrouped).map(group => (
                <div key={group} style={{ marginBottom: 12 }}>
                  <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)', marginBottom: 6, paddingLeft: 4 }}>{group}</div>
                  <div style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
                    {extrasGrouped[group].map(e => {
                      const on = extraIds.includes(e.id);
                      return (
                        <div key={e.id} className="lc-toggle-row" onClick={() => toggleExtra(e.id)}>
                          <div style={{ flex: 1 }}>
                            <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }}>{e.name}</div>
                            {e.price > 0 && <div style={{ fontSize: 11, fontFamily: 'var(--font-mono)', color: 'var(--orange)', fontWeight: 700, marginTop: 2 }}>+ {e.price.toFixed(2).replace('.', ',')} €</div>}
                          </div>
                          <div className={`lc-checkbox ${on ? 'lc-checkbox--on' : ''}`}>
                            {on && <I.Check size={12} stroke="#fff" sw={3}/>}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              ))}
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
          disabled={!wizardComplete}
          onClick={() => {
            if (!wizardComplete) return;
            // Build payload aligned with FoodKing OrderItem composition_snapshot
            const lineItem = {
              ...item,
              variationId,
              variationLabel: variationId ? (variations.find(v => v.id === variationId) || {}).name : null,
              addonChoices,
              wizardSelections: wizard,
              extraIds,
              extraLabels: extraIds.map(id => ((item.extras || []).find(e => e.id === id) || {}).name).filter(Boolean),
              sups: extraIds,                   // backwards-compat with cart UI
              qty,
              unitPrice,
              lineTotal: total,
            };
            addToCart(lineItem);
            go('cart');
          }}
          className="lc-btn"
          style={{
            background: wizardComplete ? 'var(--ink)' : 'var(--gray-2)',
            color: wizardComplete ? '#fff' : 'var(--gray-4)',
            width: '100%', height: 60,
            justifyContent: 'space-between', padding: '0 24px',
            cursor: wizardComplete ? 'pointer' : 'not-allowed',
          }}
        >
          <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <I.Bag size={18} stroke={wizardComplete ? 'var(--yellow)' : 'var(--gray-4)'}/>
            {wizardComplete ? 'Ajouter au panier' : 'Termine ta composition'}
          </span>
          <span style={{ color: wizardComplete ? 'var(--yellow)' : 'var(--gray-4)' }}>{total.toFixed(2).replace('.', ',')} €</span>
        </button>
      </div>
    </div>
  );
}

// CART
function ScreenCart({ go, cart, setCart }) {
  const total = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const updateQty = (idx, d) => setCart(c => c.map((it, i) => i === idx ? { ...it, qty: Math.max(1, it.qty + d) } : it));
  const remove = (idx) => setCart(c => c.filter((_, i) => i !== idx));
  return (
    <div data-screen-label="10 Cart" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 200 }}>
        <ScreenHeader left={<IconBtn onClick={() => go('back')}><I.Back size={20}/></IconBtn>} center={<div style={{ fontSize: 13, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>PANIER</div>}/>
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
                <Slot id={it.slot} h="100%" radius={0} placeholder={it.name}/>
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 14, fontWeight: 700 }}>{it.name}</div>
                <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>+ {(it.sups||[]).length} suppléments</div>
                <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <div className="lc-stepper">
                    <button onClick={() => updateQty(idx, -1)}>−</button>
                    <span className="lc-stepper-val">{it.qty}</span>
                    <button onClick={() => updateQty(idx, +1)}>+</button>
                  </div>
                  <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--orange)' }}>{(it.price*it.qty).toFixed(2).replace('.', ',')} €</span>
                </div>
              </div>
              <button onClick={() => remove(idx)} style={{ background: 'transparent', border: 0, color: 'var(--gray-3)', cursor: 'pointer' }}><I.Trash size={16}/></button>
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
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name}/>
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
        <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', padding: '16px 20px 36px', borderTop: '1px solid var(--gray-1)' }}>
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 12 }}>
            <div>
              <div style={{ fontSize: 11, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>Total</div>
              <div className="lc-display" style={{ fontSize: 36, lineHeight: 1 }}>{total.toFixed(2).replace('.', ',')} €</div>
            </div>
            <span style={{ fontSize: 11, color: 'var(--gray-3)' }}>TVA incluse</span>
          </div>
          <button onClick={() => go('confirm')} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 60, justifyContent: 'space-between', padding: '0 24px' }}>
            Valider ma commande <I.Arrow size={18}/>
          </button>
        </div>
      )}
    </div>
  );
}

// CONFIRMATION
function ScreenConfirm({ go }) {
  return (
    <div data-screen-label="11 Confirmation" style={{ position: 'absolute', inset: 0, background: '#FFD93D', display: 'flex', flexDirection: 'column', paddingTop: 'var(--ios-safe-top)' }}>
      <ScreenHeader left={<IconBtn bg="var(--ink)" color="#fff" onClick={() => go('home')}><I.Close size={18}/></IconBtn>} center={<Logo size={12}/>}/>
      <div style={{ flex: 1, padding: '4px 20px 20px', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
        <div style={{ textAlign: 'center' }}>
          <div style={{ width: 56, height: 56, borderRadius: 999, background: 'var(--ink)', color: 'var(--orange)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxShadow: '4px 4px 0 var(--paper)' }}>
            <I.Check size={28} sw={3}/>
          </div>
          <h1 className="lc-display" style={{ margin: '8px 0 0', fontSize: 36, lineHeight: 0.9 }}>C'est parti !</h1>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--ink)' }}>Commande <b>#C-1234</b> envoyée</p>
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
            <QRMock size={150} value="LECAY-ORDER-1234"/>
          </div>
          <div style={{ textAlign: 'center', fontFamily: 'var(--font-display)', fontSize: 24, letterSpacing: '0.1em', marginTop: 6 }}>C-1234</div>
          <div style={{ borderTop: '1.2px dashed var(--gray-2)', marginTop: 10, paddingTop: 8, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
            <div>
              <div style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Prêt à</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18 }}>19h45</div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Total</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: 'var(--orange)' }}>33,00 €</div>
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
function ScreenOrders({ go }) {
  const [tab, setTab] = uS('current');
  return (
    <div data-screen-label="12 Orders" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 100 }}>
        <div style={{ padding: '16px 20px 6px' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Commandes</h1>
        </div>
        {/* tabs */}
        <div style={{ display: 'flex', gap: 6, padding: '14px 20px 0' }}>
          {[{ id: 'current', label: 'En cours', count: 1 }, { id: 'history', label: 'Historique', count: 8 }].map(t => {
            const active = tab === t.id;
            return (
              <button key={t.id} onClick={() => setTab(t.id)} style={{ padding: '10px 18px', borderRadius: 999, border: 0, background: active ? 'var(--ink)' : 'var(--cream)', color: active ? 'var(--yellow)' : 'var(--ink)', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
                {t.label}
                <span style={{ background: active ? 'var(--orange)' : 'var(--gray-2)', color: '#fff', fontSize: 10, padding: '2px 6px', borderRadius: 999 }}>{t.count}</span>
              </button>
            );
          })}
        </div>
        {tab === 'current' ? (
          <div style={{ padding: '20px 20px 0' }}>
            {/* Active card */}
            <div onClick={() => go('confirm')} style={{ background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 20, position: 'relative', overflow: 'hidden', cursor: 'pointer' }}>
              <div style={{ position: 'absolute', top: -20, right: -20, width: 140, height: 140, opacity: 0.06 }}><I.Pepper size={140} stroke="#FF5A1F"/></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span className="lc-pill" style={{ background: 'var(--orange)', color: '#fff' }}><span className="lc-status-dot" style={{ background: '#fff' }}/> EN PRÉPARATION</span>
                <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'rgba(255,255,255,0.6)' }}>#C-1234</span>
              </div>
              <div className="lc-display" style={{ fontSize: 36, marginTop: 14, color: 'var(--yellow)' }}>~12 MIN</div>
              <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>Box Nashville · Le Cheese · Bowl Cheesy</div>
              <div style={{ display: 'flex', gap: 4, marginTop: 16 }}>
                {[1,2,3,4].map(i => (
                  <div key={i} style={{ flex: 1, height: 4, borderRadius: 999, background: i <= 2 ? 'var(--orange)' : 'rgba(255,255,255,0.15)' }}/>
                ))}
              </div>
              <div style={{ marginTop: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 14, borderTop: '1px solid rgba(255,255,255,0.1)' }}>
                <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--yellow)' }}>Voir le QR de retrait →</span>
                <span style={{ fontFamily: 'var(--font-mono)', fontWeight: 700 }}>33,00 €</span>
              </div>
            </div>
          </div>
        ) : (
          <div style={{ padding: '20px 20px 0' }}>
            {/* Stats banner */}
            <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
              <div style={{ flex: 1, background: 'var(--ink)', color: '#fff', borderRadius: 14, padding: '12px 14px' }}>
                <div style={{ fontSize: 10, color: 'var(--yellow)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>Commandes</div>
                <div className="lc-display" style={{ fontSize: 26, color: '#fff' }}>8</div>
              </div>
              <div style={{ flex: 1, background: 'var(--orange)', color: '#fff', borderRadius: 14, padding: '12px 14px' }}>
                <div style={{ fontSize: 10, color: 'rgba(255,255,255,0.85)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>Dépensé</div>
                <div className="lc-display" style={{ fontSize: 26 }}>184€</div>
              </div>
              <div style={{ flex: 1, background: 'var(--yellow)', color: 'var(--ink)', borderRadius: 14, padding: '12px 14px' }}>
                <div style={{ fontSize: 10, color: 'var(--ink)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>Pts</div>
                <div className="lc-display" style={{ fontSize: 26 }}>347</div>
              </div>
            </div>
            {[
              { date: 'HIER', items: [{ id: 'C-1212', items: 'Box Familiale', total: 29.00 }, { id: 'C-1208', items: 'Smash Cheese × 2 · Frites', total: 21.00 }] },
              { date: '30 AVRIL', items: [{ id: 'C-1190', items: 'Wrap Poulet · Bowl Cheesy', total: 22.00 }] },
              { date: '24 AVRIL', items: [{ id: 'C-1142', items: 'Box Nashville · Coca', total: 18.00 }] },
            ].map((g, gi) => (
              <div key={gi} style={{ marginBottom: 16 }}>
                <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--orange)', letterSpacing: '0.22em', marginBottom: 8, paddingLeft: 4 }}>● {g.date}</div>
                <div style={{ display: 'grid', gap: 8 }}>
                  {g.items.map(o => (
                    <div key={o.id} onClick={() => go('orderDetail', o.id)} style={{ background: 'var(--cream)', borderRadius: 14, padding: 14, position: 'relative', cursor: 'pointer' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                            <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--gray-3)' }}>#{o.id}</span>
                            <span style={{ width: 4, height: 4, borderRadius: 999, background: 'var(--gray-2)' }}/>
                            <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--green)', textTransform: 'uppercase', letterSpacing: '0.08em' }}>✓ Récupérée</span>
                          </div>
                          <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.3 }}>{o.items}</div>
                          <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                            <span style={{ fontFamily: 'var(--font-display)', fontSize: 22, color: 'var(--orange)' }}>{o.total.toFixed(2).replace('.', ',')} €</span>
                            <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 600 }}>+{Math.round(o.total)} pts</span>
                          </div>
                        </div>
                        <button onClick={(e) => { e.stopPropagation(); go('menu'); }} style={{ background: 'var(--ink)', color: 'var(--yellow)', border: 0, padding: '10px 14px', borderRadius: 999, fontSize: 10, fontWeight: 700, cursor: 'pointer', textTransform: 'uppercase', letterSpacing: '0.08em', flexShrink: 0, alignSelf: 'center' }}>↻ Refaire</button>
                      </div>
                    </div>
                  ))}
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
        {/* loyalty preview card */}
        <div style={{ padding: '14px 20px 0' }}>
          <div onClick={() => go('loyalty')} style={{ position: 'relative', background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 20, overflow: 'hidden', cursor: 'pointer' }}>
            <div style={{ position: 'absolute', top: -30, right: -30, width: 180, height: 180, borderRadius: 999, background: 'var(--orange)', opacity: 0.18 }}/>
            <div style={{ position: 'absolute', top: -10, right: -10, width: 100, height: 100, borderRadius: 999, background: 'var(--yellow)', opacity: 0.18 }}/>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
              <I.Gift size={18} stroke="var(--yellow)"/>
              <span className="lc-eyebrow" style={{ color: 'var(--yellow)' }}>Carte fidélité</span>
            </div>
            <div className="lc-display" style={{ fontSize: 56, lineHeight: 0.9 }}>347<span style={{ fontSize: 18, color: 'var(--orange)' }}> PTS</span></div>
            <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>Plus que <b style={{ color: 'var(--orange)' }}>153 pts</b> pour ton burger gratuit 🍔</div>
            <div style={{ marginTop: 14, height: 6, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
              <div style={{ width: '69%', height: '100%', background: 'linear-gradient(90deg, var(--yellow), var(--orange))' }}/>
            </div>
            <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12, fontWeight: 700, color: 'var(--yellow)' }}>
              <span>VOIR MON QR →</span>
              <I.QR size={18}/>
            </div>
          </div>
        </div>
        {/* menu items */}
        <div style={{ padding: '24px 20px 0' }}>
          <div style={{ background: 'var(--cream)', borderRadius: 18, overflow: 'hidden' }}>
            {[
              { i: I.Gift, t: 'Ma carte fidélité', d: '347 pts', go: 'loyalty', accent: 'var(--orange)' },
              { i: I.Card, t: 'Moyens de paiement', d: 'Visa ····4242' },
              { i: I.Bell, t: 'Notifications', d: 'Activées' },
              { i: I.Pepper, t: 'Allergènes & préférences', d: '2 actives' },
              { i: I.Globe, t: 'Langue', d: 'Français' },
              { i: I.Phone, t: 'Nous contacter', d: '06 51 30 XX XX' },
              { i: I.Shield, t: 'CGU & Confidentialité' },
            ].map((row, i) => (
              <div key={i} className="lc-toggle-row" style={{ cursor: 'pointer' }} onClick={() => row.go ? go(row.go) : go('toast', { msg: row.t + ' — bientôt disponible', kind: 'info' })}>
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
function ScreenLoyalty({ go }) {
  const [tab, setTab] = uS('points');
  const points = 347;
  const goal = 500;
  const pct = (points / goal) * 100;
  return (
    <div data-screen-label="14 Loyalty" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 40 }}>
        {/* yellow top */}
        <div style={{ background: 'var(--yellow)', padding: '0 0 28px', position: 'relative', overflow: 'hidden' }}>
          <div style={{ position: 'absolute', inset: 0, opacity: 0.08, backgroundImage: 'radial-gradient(circle at 18% 22%, rgba(0,0,0,0.5) 0 2px, transparent 2px), radial-gradient(circle at 78% 65%, rgba(0,0,0,0.5) 0 2px, transparent 2px)', backgroundSize: '60px 60px' }}/>
          <ScreenHeader left={<IconBtn onClick={() => go('back')} bg="var(--ink)" color="#fff"><I.Back size={20}/></IconBtn>} center={<div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>Carte fidélité</div>} right={<IconBtn bg="var(--ink)" color="#fff"><I.Settings size={18}/></IconBtn>}/>
          {/* QR card — centered */}
          <div style={{ display: 'flex', justifyContent: 'center', padding: '8px 20px 0' }}>
            <div style={{ background: '#fff', borderRadius: 22, padding: 18, boxShadow: '0 12px 32px rgba(0,0,0,0.14)', display: 'inline-block' }}>
              <div className="lc-pulse" style={{ borderRadius: 12, padding: 4, display: 'inline-block' }}>
                <QRMock size={208}/>
              </div>
              <div style={{ marginTop: 10, textAlign: 'center' }}>
                <div className="lc-eyebrow" style={{ color: 'var(--gray-3)' }}>LE CAYENNE FIDÉLITÉ</div>
                <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--gray-3)', marginTop: 2 }}>#FK-12345 · IKYES B.</div>
              </div>
            </div>
          </div>
          <div style={{ marginTop: 14, textAlign: 'center', fontSize: 12, color: 'var(--ink)', fontWeight: 600 }}>Présente ce code à la caisse pour ajouter ou utiliser tes points</div>
        </div>
        {/* points card black */}
        <div style={{ margin: '-20px 20px 0', background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 22, position: 'relative', overflow: 'hidden' }}>
          <div style={{ position: 'absolute', top: -40, right: -40, width: 180, height: 180, borderRadius: 999, background: 'var(--orange)', opacity: 0.2 }}/>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
            <div className="lc-display" style={{ fontSize: 64, lineHeight: 1 }}>{points}</div>
            <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: 'var(--orange)' }}>POINTS</div>
          </div>
          <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.6)', marginTop: 4 }}>Soit {(points/100).toFixed(2).replace('.', ',')} € de réduction disponible</div>
          <div style={{ marginTop: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: 'rgba(255,255,255,0.6)', marginBottom: 6 }}>
              <span>{points} / {goal} pts</span>
              <span style={{ color: 'var(--yellow)' }}>BURGER OFFERT</span>
            </div>
            <div style={{ height: 8, background: 'rgba(255,255,255,0.12)', borderRadius: 999, overflow: 'hidden', position: 'relative' }}>
              <div style={{ width: pct + '%', height: '100%', background: 'linear-gradient(90deg, var(--yellow), var(--orange))' }}/>
            </div>
            <div style={{ marginTop: 8, fontSize: 12, fontWeight: 600 }}>Plus que <span style={{ color: 'var(--orange)', fontWeight: 800 }}>153 pts</span> pour ton prochain <b style={{ color: 'var(--yellow)' }}>BURGER GRATUIT</b> 🍔</div>
          </div>
        </div>
        {/* tabs */}
        <div style={{ display: 'flex', gap: 6, padding: '20px 20px 0' }}>
          {[{ id: 'points', label: 'Mes points' }, { id: 'rewards', label: 'Récompenses' }, { id: 'history', label: 'Historique' }].map(t => {
            const a = tab === t.id;
            return <button key={t.id} onClick={() => setTab(t.id)} style={{ flex: 1, padding: '10px 0', borderRadius: 999, border: 0, background: a ? 'var(--ink)' : 'var(--cream)', color: a ? 'var(--yellow)' : 'var(--ink)', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer' }}>{t.label}</button>;
          })}
        </div>
        {/* tab content */}
        <div style={{ padding: '16px 20px 0' }}>
          {tab === 'points' && (
            <div style={{ display: 'grid', gap: 8 }}>
              {[
                { p: 100, r: '-1 € de réduction', unlocked: true },
                { p: 500, r: '-5 € sur ta commande', need: 153 },
                { p: 1000, r: 'Burger gratuit 🍔', need: 653 },
                { p: 2000, r: 'Box Familiale -50%', need: 1653 },
              ].map((r, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, background: r.unlocked ? '#E8F8ED' : 'var(--cream)', borderRadius: 14, border: r.unlocked ? '1.5px solid var(--green)' : '1.5px solid transparent' }}>
                  <div style={{ width: 44, height: 44, borderRadius: 10, background: r.unlocked ? 'var(--green)' : 'var(--ink)', color: r.unlocked ? '#fff' : 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 14, flexShrink: 0 }}>{r.p}</div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13, fontWeight: 700 }}>{r.r}</div>
                    <div style={{ fontSize: 11, color: 'var(--gray-3)', marginTop: 2 }}>{r.unlocked ? '✓ Disponible' : `${r.need} pts manquants`}</div>
                  </div>
                  {r.unlocked && <button onClick={() => go('redeem')} style={{ background: 'var(--green)', color: '#fff', border: 0, padding: '8px 14px', borderRadius: 999, fontSize: 11, fontWeight: 700, cursor: 'pointer' }}>UTILISER</button>}
                </div>
              ))}
            </div>
          )}
          {tab === 'rewards' && (
            <div style={{ display: 'grid', gap: 10 }}>
              {[
                { name: 'Frites offertes', pts: 100, unlocked: true },
                { name: '-5€ sur ta commande', pts: 500, locked: true, need: 153 },
                { name: 'Burger gratuit', pts: 1000, locked: true, need: 653 },
                { name: 'Box Familiale -50%', pts: 2000, locked: true, need: 1653 },
              ].map((r, i) => (
                <div key={i} style={{ padding: 16, background: 'var(--cream)', borderRadius: 16, position: 'relative', overflow: 'hidden' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                    <span className="lc-pill lc-pill--ink" style={{ fontSize: 10 }}>{r.pts} PTS</span>
                    {r.locked && <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700 }}>🔒 -{r.need} PTS</span>}
                  </div>
                  <div className="lc-display" style={{ fontSize: 22 }}>{r.name}</div>
                  <button onClick={() => !r.locked && go('redeem')} disabled={r.locked} style={{ marginTop: 12, width: '100%', height: 44, borderRadius: 12, border: 0, background: r.locked ? 'var(--gray-2)' : 'var(--orange)', color: '#fff', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', cursor: r.locked ? 'not-allowed' : 'pointer' }}>{r.locked ? 'Verrouillé' : 'Échanger'}</button>
                </div>
              ))}
            </div>
          )}
          {tab === 'history' && (
            <div style={{ display: 'grid', gap: 8 }}>
              {[
                { d: '8 mai', a: '+25', r: 'Box Nashville', good: true },
                { d: '5 mai', a: '+12', r: 'Smash Cheese', good: true },
                { d: '2 mai', a: '−500', r: 'Burger gratuit utilisé', good: false },
                { d: '30 avril', a: '+22', r: 'Wrap Poulet · Bowl', good: true },
                { d: '28 avril', a: '+15', r: 'Le Gourmet', good: true },
              ].map((h, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', background: 'var(--cream)', borderRadius: 12 }}>
                  <div style={{ width: 36, height: 36, borderRadius: 10, background: h.good ? 'var(--green)' : 'var(--red)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{h.good ? <I.Plus size={16}/> : <I.Minus size={16}/>}</div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 13, fontWeight: 600 }}>{h.r}</div>
                    <div style={{ fontSize: 11, color: 'var(--gray-3)' }}>{h.d}</div>
                  </div>
                  <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: h.good ? 'var(--green)' : 'var(--red)' }}>{h.a}</div>
                </div>
              ))}
            </div>
          )}
        </div>
        {/* link physical card */}
        <div style={{ padding: '20px 20px 0' }}>
          <div style={{ background: 'var(--ink)', color: '#fff', borderRadius: 16, padding: 16, display: 'flex', alignItems: 'center', gap: 12 }}>
            <div style={{ width: 44, height: 44, borderRadius: 10, background: 'var(--orange)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><I.Card size={20}/></div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 13, fontWeight: 700 }}>Tu as une carte plastique ?</div>
              <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.6)', marginTop: 2 }}>Lie-la à ton compte pour cumuler partout.</div>
            </div>
            <button onClick={() => go('link')} style={{ background: 'var(--yellow)', color: 'var(--ink)', border: 0, padding: '8px 14px', borderRadius: 999, fontSize: 11, fontWeight: 700, cursor: 'pointer' }}>LIER</button>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { ScreenHome, ScreenMenu, ScreenItem, ScreenCart, ScreenConfirm, ScreenOrders, ScreenProfile, ScreenLoyalty, ITEMS, CATS });
