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
    branch: { name: 'Le Cayenne', city: 'Hénin-Beaumont', zip: '62110' },
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

function branchPhoneInfo() {
  const raw = String((window.LC.menu.branch && window.LC.menu.branch.phone) || '').trim();
  const digits = raw.replace(/\D/g, '');
  return {
    digits,
    display: digits ? digits.replace(/(\d{2})(?=\d)/g, '$1 ') : '—',
    href: digits ? `tel:${digits}` : null,
  };
}

// [GOAL-SYNC 2026-07-08] Config fidélité RÉELLE (contrat §2) — les taux viennent de la data
// layer alignée sur GET /api/frontend/loyalty/config (points_per_euro=10, points_for_1_euro_
// discount=100). Plus AUCUN ratio hardcodé dans les écrans. Supporte les deux formes de
// LC.loyalty.config (objet legacy / fonction) ; fallback = vérité backend (10 pt/€, 100 pts = 1 €).
function lcLoyaltyCfg() {
  const L = (window.LC && window.LC.loyalty) || null;
  const c = L ? (typeof L.config === 'function' ? L.config() : L.config) : null;
  return c || {};
}
function lcEarnRatio() {
  const c = lcLoyaltyCfg();
  const r = Number(c.earn_ratio != null ? c.earn_ratio : c.points_per_euro);
  return (isFinite(r) && r > 0) ? r : 10;
}
// [2026-11-14] Le compte SMS porte au serveur le nom technique « Guest User » : jamais affiché.
function lcHumanName(raw) {
  const n = String(raw || '').trim();
  return /^(guest|invit[ée]|client)(\s+user)?$/i.test(n) ? '' : n;
}
// [2026-11-14] Seuil d'utilisation des points = serveur (min_redeem_points 1000, paliers 1000/2000) :
// l'app promettait −1 € dès 100 pts alors que le serveur refuse tout usage sous 1 000 points (R2-002).
function lcMinRedeem() {
  const c = lcLoyaltyCfg();
  const m = Number(c.min_redeem_points);
  return (isFinite(m) && m > 0) ? m : 1000;
}
function lcRedeemTiers() {
  const c = lcLoyaltyCfg();
  const t = Array.isArray(c.tiers) ? c.tiers.map(Number).filter(n => isFinite(n) && n > 0) : [];
  return t.length ? t : [lcMinRedeem()];
}
function lcRedeemRatio() {
  const c = lcLoyaltyCfg();
  const r = Number(c.points_for_1_euro_discount != null ? c.points_for_1_euro_discount : c.redeem_ratio);
  return (isFinite(r) && r > 0) ? r : 100;
}

// [MOBILE-EXCELLENCE-PHASE15] Customer-facing reactivation must use the
// public local state APIs. The dev namespace is intentionally not required in
// the shipped mobile build. Reactivation starts a fresh zero-point account,
// matching the existing opt-out copy and local erasure behavior.
function reactivateLoyaltyAccount() {
  const lc = window.LC || {};
  const storage = lc.storage || {};
  const loyalty = lc.loyalty || {};

  if (typeof storage.setConsent === 'function') {
    storage.setConsent('opted_in');
  }
  if (typeof loyalty._replaceAccount === 'function') {
    loyalty._replaceAccount({
      consent_status: 'opted_in',
      balance: 0,
      lifetime_earned: 0,
      lifetime_redeemed: 0,
    });
  }

  // _replaceAccount persists state but does not notify listeners itself.
  // Keep the existing event contract so ScreenLoyalty re-renders immediately.
  if (typeof window.dispatchEvent === 'function') {
    const detail = { type: 'consent', status: 'opted_in', source: 'customer' };
    if (typeof window.CustomEvent === 'function') {
      window.dispatchEvent(new window.CustomEvent('lc:loyalty-changed', { detail }));
    } else if (typeof window.Event === 'function') {
      window.dispatchEvent(new window.Event('lc:loyalty-changed'));
    }
  }
}

// [UI/UX 2026-11-15] Barre de progression qui se remplit à l'ouverture (0 → valeur), transition CSS .lc-progress-fill.
function ProgressFill({ pct }) {
  const [w, setW] = uS(0);
  uE(() => { const id = requestAnimationFrame(() => setW(Math.max(0, Math.min(100, Number(pct) || 0)))); return () => cancelAnimationFrame(id); }, [pct]);
  return <div className="lc-progress-fill" style={{ width: w + '%', height: '100%', background: 'linear-gradient(90deg, var(--yellow), var(--orange))' }}/>;
}

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

// [MOBILE-EXCELLENCE-PHASE13] EU FIC 1169/2011 — disclosure textuelle des 14 allergènes
// majeurs sur tout produit alimentaire commercialisé. Les cartes restent neutres ; la
// disclosure complète est réservée aux surfaces de détail et de récapitulatif.
// ALLERGEN_META reste l'unique vocabulaire d'affichage français.
const ALLERGEN_META = {
  gluten:     { label: 'Gluten' },
  lactose:    { label: 'Lactose' },
  oeuf:       { label: 'Œuf' },
  arachide:   { label: 'Arachide' },
  fruits_coque: { label: 'Fruits à coque' },
  soja:       { label: 'Soja' },
  poisson:    { label: 'Poisson' },
  crustaces:  { label: 'Crustacés' },
  mollusques: { label: 'Mollusques' },
  celeri:     { label: 'Céleri' },
  moutarde:   { label: 'Moutarde' },
  sesame:     { label: 'Sésame' },
  sulfites:   { label: 'Sulfites' },
  lupin:      { label: 'Lupin' },
};

function AllergenDisclosure({ size = 'sm' }) {
  // [2026-11-14] P0 relecture adverse : la liste affichée était DEVINÉE par catégorie (« Gluten » seul sur
  // un burger au cheddar et à la mayonnaise) et le serveur n'a de fiche allergènes que pour 1 produit sur 72.
  // Une information santé fausse est pire qu'aucune : on renvoie vers l'équipe tant que la fiche réelle
  // n'est pas saisie côté restaurant (règlement UE 1169/2011 — information orale permise si annoncée).
  const isLg = size === 'lg';
  const tel = (typeof branchPhoneInfo === 'function' && branchPhoneInfo()) || {};
  return (
    <div data-testid="allergen-disclosure" role="note" aria-label="Informations allergènes"
      style={{ marginTop: isLg ? 16 : 10, padding: isLg ? '12px 14px' : '9px 11px', border: '1px solid var(--gray-2)', borderRadius: 14, background: '#fff', color: 'var(--ink)' }}>
      <div style={{ fontSize: isLg ? 13 : 11, fontWeight: 750, lineHeight: 1.3 }}>Allergies ou intolérances ?</div>
      <p style={{ margin: '6px 0 0', color: 'var(--gray-4)', fontSize: isLg ? 12 : 11, lineHeight: 1.45 }}>
        Demande-nous la composition exacte avant de commander : en caisse{tel.display ? <> ou au <a href={tel.href || undefined} style={{ color: 'var(--ink)', fontWeight: 700 }}>{tel.display}</a></> : null}.
      </p>
    </div>
  );
}

// Compatibility export for the older static catalogue contract. It now renders
// the same text-first disclosure and is not a visual badge or icon surface.
const AllergenBadge = AllergenDisclosure;

// HOME
// The owner-curated presentation order is deliberately a slug list: it only
// orders products already published by the loaded catalogue and never creates
// a mobile-only product, price, option, or composition rule.
const OWNER_BESTSELLER_SLUGS = Object.freeze([
  'cayenne',
  'tacos-l',
  'terminator',
  'grill-burger',
  'big-burger',
  'bol-frites',
  'tacos-xl',
]);

// [GOAL-SYNC 2026-07-08] Plus de « Ikyes B. » hardcodé : avatar + aria-label dérivés du prop
// name (posé par index.html depuis le profil réel / storage). Fallback neutre FR si absent.
function ScreenHome({ go, name = '' }) {
  const screenRef = useScreenScrollReset('home');
  // [UI/UX 2026-11-15] L'entrée orchestrée (titre puis carte signature) ne se joue qu'une fois par session.
  const [firstEntry] = uS(() => { try { if (sessionStorage.getItem('lc.home.entered')) return false; sessionStorage.setItem('lc.home.entered', '1'); return true; } catch (_e) { return true; } });
  // [2026-11-14] Le compte créé par SMS porte le nom technique « Guest User » côté serveur :
  // ne jamais l'afficher (« Bonjour, Invité / GUEST »).
  const rawName = String(name || '').trim();
  const safeName = /^(guest|invit[ée]|client)(\s+user)?$/i.test(rawName) ? '' : rawName;
  const avatarInitial = (safeName ? safeName.charAt(0) : 'C').toUpperCase();
  const greeting = (new Date().getHours() < 20 && new Date().getHours() >= 5) ? 'Bonjour' : 'Bonsoir';
  const currentItems = window.LC.menu.items || [];
  // [2026-11-14] Ouvert / fermé et horaires = serveur (/api/frontend/horaires) ; rien d'affirmé si inconnu.
  const hours = (window.LC.hoursInfo && window.LC.hoursInfo()) || null;
  const featured = currentItems.find(item => item.slug === 'cayenne') || currentItems[0];
  const featuredLines = featured ? featured.name.toUpperCase().split(' ') : ['LE', 'CAYENNE'];
  const featuredTop = featuredLines.slice(0, 2).join(' ');
  const featuredBottom = featuredLines.slice(2).join(' ');
  const featuredItems = OWNER_BESTSELLER_SLUGS
    .map(slug => currentItems.find(item => item.slug === slug))
    .filter(Boolean);
  const newItems = [
    ...currentItems.filter(i => i.is_new || (i.tags || []).includes('NOUVEAU')),
    ...currentItems.filter(i => (i.tags || []).includes('TOP')),
  ]
    .filter((item, index, items) => items.findIndex(candidate => candidate.id === item.id) === index)
    .slice(0, 4);
  return (
    <div ref={screenRef} data-screen-label="07 Home" className={'lc-home' + (firstEntry ? ' lc-home--first' : '')}>
      <div className="lc-screen lc-home__scroll">
        <header className="lc-home__header">
          <button type="button" onClick={() => go('profile')} aria-label={safeName ? `Profil — ${safeName}` : 'Profil'} className="lc-home__avatar">{avatarInitial}</button>
          <div className="lc-home__brand" aria-label={hours ? `Le Cayenne — ${hours.label}` : 'Le Cayenne'}>
            <Logo size={18}/>
            {hours && <span className={'lc-home__status' + (hours.open ? '' : ' is-closed')} data-testid="home-open-status"><i aria-hidden="true"/> {hours.label}</span>}
          </div>
          {/* [2026-11-14] Cloche retirée : la fonction notifications n'existe pas encore. */}
          <span className="lc-home__notify" aria-hidden="true" style={{ width: 44, height: 44 }}/>
        </header>

        <section className="lc-home__intro" aria-labelledby="home-title">
          <p className="lc-eyebrow">Le Cayenne · Hénin-Beaumont</p>
          <h1 id="home-title" className="lc-display"><span>{greeting},</span><br/><em>{safeName || 'bienvenue'}</em> !</h1>
          <p className="lc-home__lead">Prêt quand tu l'es.</p>
        </section>

        <Marquee items={['Sauce Cayenne maison', 'Sandwichs faluche', 'Tacos M & L', 'Bols frites / riz', 'Burgers briochés', 'Prêt en 8 min']}/>

        <section className="lc-home__hero-wrap" aria-label="Suggestion signature">
          {featured && (
            <button type="button" className="lc-home__hero lc-tap" aria-label={`Commander ${featured.name}`} onClick={() => go('item', featured.slug)}>
              <div className="lc-home__hero-copy">
                <span className="lc-pill lc-pill--yellow">Signature du Cayenne</span>
                <h2 className="lc-display">{featuredTop}<br/>{featuredBottom}</h2>
                <span className="lc-home__hero-cta">Commander <I.Arrow size={15} stroke="var(--yellow)"/></span>
              </div>
              <div className="lc-home__hero-art">
                <Slot id={featured.thumb} h="100%" radius={0} placeholder={featured.name} src={featured.hero} alt={featured.name} fit="contain"/>
                <strong>{featured.price.toFixed(2).replace('.', ',')} €</strong>
              </div>
              <span className="lc-home__hero-stamp" aria-hidden="true">FAIT<br/>MAISON</span>
            </button>
          )}
        </section>

        <section className="lc-home__section" aria-labelledby="categories-title">
          <div className="lc-home__section-head"><div><p className="lc-eyebrow">Choisir son envie</p><h2 id="categories-title" className="lc-display">Catégories</h2></div><span>{CATS.length} choix</span></div>
          <div className="lc-home__categories">
            {CATS.map(c => (
              <button type="button" key={c.id} aria-label={`Catégorie ${c.label}`} className="lc-home__category lc-tap" onClick={() => go('menu', c.id)}>
                <Slot id={`home-category-${c.id}`} h={88} radius={14} src={c.image} alt={c.label} fit="contain"/>
                <span>{c.label}</span>
                <I.Arrow size={13}/>
              </button>
            ))}
          </div>
        </section>

        <section className="lc-home__section" aria-labelledby="favourites-title">
          <div className="lc-home__section-head"><div><p className="lc-eyebrow">Les préférés de nos clients</p><h2 id="favourites-title" className="lc-display">Nos meilleures ventes</h2></div><button type="button" onClick={() => go('menu')} className="lc-home__see-all">Voir tout <I.Arrow size={13}/></button></div>
          <div className="lc-home__rail" role="list" aria-label="Meilleures ventes">
            {featuredItems.map(it => (
              <button type="button" key={it.id} role="listitem" aria-label={`Voir ${it.name}`} className="lc-home__product lc-tap" onClick={() => go('item', it.id)}>
                <div className="lc-home__product-art"><Slot id={it.thumb} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name} fit="contain"/>{it.tags[0] && <Tag t={it.tags[0]}/>}</div>
                <div className="lc-home__product-meta"><strong>{it.name}</strong>{it.slug === 'tacos-xl' && <em className="lc-home__product-note">Pour les gourmands</em>}<span>{it.price.toFixed(2).replace('.', ',')} €</span></div>
              </button>
            ))}
          </div>
        </section>

        <section className="lc-home__section" aria-labelledby="new-title">
          <div className="lc-home__section-head"><div><p className="lc-eyebrow">À découvrir</p><h2 id="new-title" className="lc-display">Nouveautés</h2></div><span>{newItems.length} plats</span></div>
          <div className="lc-home__new-grid">
            {newItems.map(it => (
              <button type="button" key={it.id} aria-label={`Voir ${it.name}`} className="lc-home__new-card lc-tap" onClick={() => go('item', it.id)}>
                <div className="lc-home__new-art"><Slot id={it.thumb} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name} fit="contain"/><Tag t="TOP"/></div>
                <strong>{it.name}</strong><span>{it.price.toFixed(2).replace('.', ',')} €</span>
              </button>
            ))}
          </div>
        </section>

        <section className="lc-home__restaurant" aria-labelledby="restaurant-title">
          <div className="lc-home__restaurant-mark"><I.Pepper size={74} stroke="var(--orange)"/></div>
          <p className="lc-eyebrow">Notre maison</p>
          <h2 id="restaurant-title" className="lc-display">Le Cayenne<br/><em>Hénin-Beaumont</em></h2>
          <p>Abdoullah en cuisine, fait maison chaque jour. Sandwich Cayenne signature, bols frites/riz et tacos M & L — du peuple, pour le peuple.</p>
          <div className="lc-home__restaurant-meta"><span className={hours && !hours.open ? 'is-closed' : ''} data-testid="home-hours"><i aria-hidden="true"/> {hours && hours.summary ? hours.summary : 'Horaires : appelez-nous'}</span><a data-testid="branch-phone" aria-label={'Téléphone du restaurant : ' + branchPhoneInfo().display} href={branchPhoneInfo().href || undefined}>{branchPhoneInfo().display}</a></div>
        </section>
      </div>
    </div>
  );
}

// MENU
function ScreenMenu({ go, cart, addToCart, initialFilter = 'all' }) {
  const screenRef = useScreenScrollReset('menu');
  const [filter, setFilter] = uS(initialFilter || 'all');
  // [2026-11-14] Recherche réelle (le bouton n'ouvrait qu'un « bientôt disponible ») : nom, description,
  // catégorie ; « poulet » trouve aussi les plats au poulet (Chicken, Tenders, Nuggets).
  const [searchOpen, setSearchOpen] = uS(false);
  const [query, setQuery] = uS('');
  const fold = (t) => String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const SYNONYMES = { poulet: ['chicken', 'tenders', 'nuggets', 'poulet'], boeuf: ['steak', 'viande hachee', 'burger'], frite: ['frites'] };
  const q = fold(query).trim();
  const matches = (i) => {
    if (!q) return true;
    const hay = fold([i.name, i.desc || i.description, i.cat].join(' '));
    const termes = [q, ...((SYNONYMES[q] || SYNONYMES[q.replace(/s$/, '')]) || [])];
    return termes.some(t => hay.indexOf(t) !== -1);
  };
  const filtered = (filter === 'all' ? ITEMS : ITEMS.filter(i => i.cat === filter)).filter(matches);
  // [UI/UX 2026-11-15] Filtres collés en haut quand on descend ; la catégorie active se recentre d'elle-même ;
  // le « + » d'ajout direct confirme d'une coche ; la barre panier tressaute à chaque ajout.
  const scrollRef = React.useRef(null);
  const chipsRef = React.useRef(null);
  const [stuck, setStuck] = uS(false);
  const [justAdded, setJustAdded] = uS(null);
  const onScroll = () => { const el = scrollRef.current; if (!el) return; const v = el.scrollTop > 70; setStuck(prev => prev === v ? prev : v); };
  uE(() => {
    const row = chipsRef.current; if (!row) return;
    const active = row.querySelector('[aria-pressed="true"]');
    if (!active || !active.scrollIntoView) return;
    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    try { active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: reduced ? 'auto' : 'smooth' }); } catch (_e) { /* ancien WebKit */ }
  }, [filter]);
  const quickTimer = React.useRef(null);
  const [annonce, setAnnonce] = uS('');
  uE(() => () => { if (quickTimer.current) clearTimeout(quickTimer.current); }, []);
  const quickAdd = (it) => {
    addToCart(it); setJustAdded(it.id);
    const n = cart.reduce((s, i) => s + (Number(i.qty) || 0), 0) + 1;
    const t = cart.reduce((s, i) => s + i.price * i.qty, 0) + it.price;
    setAnnonce(`${it.name} ajouté au panier — ${n} article${n > 1 ? 's' : ''}, ${t.toFixed(2).replace('.', ',')} €`);
    if (quickTimer.current) clearTimeout(quickTimer.current);
    quickTimer.current = setTimeout(() => setJustAdded(id => (id === it.id ? null : id)), 1600);
  };
  const cartCount = cart.reduce((n, i) => n + (Number(i.qty) || 0), 0);
  return (
    <div ref={screenRef} data-screen-label="08 Menu" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" ref={scrollRef} onScroll={onScroll} style={{ paddingBottom: 160 }}>
        <div className="lc-sr-only" role="status" aria-live="polite" data-testid="menu-announce">{annonce}</div>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', padding: '16px 20px 6px' }}>
          <div>
            <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Menu</h1>
            <div style={{ fontSize: 12, color: 'var(--gray-3)', marginTop: 4 }}>{CATS.length} catégories · {ITEMS.length} produits</div>
          </div>
          <IconBtn bg="var(--cream)" ariaLabel={searchOpen ? 'Fermer la recherche' : 'Rechercher dans le menu'} onClick={() => { setSearchOpen(o => !o); setQuery(''); }}>{searchOpen ? <I.Close size={18}/> : <I.Search size={18}/>}</IconBtn>
        </div>
        {searchOpen && (
          <div style={{ padding: '6px 20px 0' }}>
            <input type="search" autoFocus value={query} onChange={e => setQuery(e.target.value)} placeholder="Un plat, une viande, une boisson…" aria-label="Rechercher un plat" data-testid="menu-search"
              style={{ width: '100%', height: 48, borderRadius: 14, border: '1.5px solid var(--gray-2)', padding: '0 16px', fontSize: 16, background: '#fff', color: 'var(--ink)', outline: 'none', boxSizing: 'border-box' }}/>
            {q && !filtered.length && <p role="status" style={{ margin: '14px 2px 0', fontSize: 14, color: 'var(--gray-4)' }}>Aucun plat ne correspond à « {query} ».</p>}
          </div>
        )}
        {/* filter chips */}
        <div className={'lc-menu-chips' + (stuck ? ' is-stuck' : '')}>
        <div className="lc-menu-chips__row" ref={chipsRef}>
          {[{ id: 'all', label: 'Tout' }, ...CATS].map(c => {
            const active = filter === c.id;
            return (
              <button key={c.id} onClick={() => setFilter(c.id)} aria-pressed={active} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, flexShrink: 0, padding: '10px 16px', borderRadius: 999, border: active ? '0' : '1.5px solid var(--gray-2)', background: active ? 'var(--ink)' : '#fff', color: active ? 'var(--yellow)' : 'var(--ink)', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer' }}>
                {c.image && <span aria-hidden="true"><Slot id={`menu-category-${c.id}`} w={24} h={24} radius={6} src={c.image} alt="" fit="contain"/></span>}{c.label}
              </button>
            );
          })}
        </div>
        </div>
        {/* category sections */}
        {CATS.filter(c => filter === 'all' || filter === c.id).map(cat => {
          const items = ITEMS.filter(i => i.cat === cat.id).filter(matches);
          if (!items.length) return null;
          return (
            <div key={cat.id} style={{ marginTop: 18 }}>
              <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', padding: '0 20px', marginBottom: 10 }}>
                <h3 className="lc-display" style={{ margin: 0, fontSize: 24, display: 'flex', alignItems: 'center', gap: 10 }}><span aria-hidden="true"><Slot id={`section-category-${cat.id}`} w={40} h={40} radius={8} src={cat.image} alt="" fit="contain"/></span>{cat.label}</h3>
                <span style={{ fontSize: 11, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>{items.length} créations</span>
              </div>
              <div style={{ padding: '0 20px', display: 'grid', gap: 10 }}>
                {items.map(it => { const soldOut = it.available === false; return (
                  <div key={it.id} role="button" tabIndex={0} aria-label={`Voir ${it.name} — ${soldOut ? 'épuisé' : it.price.toFixed(2).replace('.', ',') + ' €'}`} className={'lc-tap lc-menu-card' + (soldOut ? ' lc-soldout' : '')} onClick={() => go('item', it.id)} onKeyDown={window.lcTapKey(() => go('item', it.id))} style={{ display: 'flex', gap: 14, padding: 12, background: 'var(--cream)', borderRadius: 16, cursor: 'pointer', alignItems: 'center' }}>
                    <div style={{ width: 84, height: 84, borderRadius: 12, overflow: 'hidden', flexShrink: 0, background: '#0A0A0A' }}>
                      <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name} fit="contain"/>
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', gap: 6, marginBottom: 4, alignItems: 'center', flexWrap: 'wrap' }}>
                        {it.tags.slice(0,2).map(t => <Tag key={t} t={t}/>)}
                      </div>
                      <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.2 }}>{it.name}</div>
                      {/* [test-e2e fix B-004 round-2 2026-05-11] CSS line-clamp 2 + tooltip — no more hard viewport clip */}
                      <div title={it.desc} style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2, lineHeight: 1.35, overflow: 'hidden', textOverflow: 'ellipsis', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' }}>{it.desc}</div>
                      <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 700, color: 'var(--orange)' }}>{it.price.toFixed(2).replace('.', ',')} €</span>
                        {/* [Owner re-cadrage 2026-05-11 D3] Quick-add "+" : si item personnalisable (viandes / sauce / suppléments / menu_addon / frites_style),
                            ouvrir le wizard au lieu d'ajouter direct au panier avec composition vide. Pour desserts/boissons/items direct, garder add direct. */}
                        {soldOut ? <span data-testid="item-soldout" style={{ display: 'inline-flex', alignItems: 'center', height: 28, padding: '0 12px', borderRadius: 999, background: 'var(--ink)', color: '#FFFFFF', fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase', opacity: 1 }}>Épuisé</span>
                          : (it.viandes > 0 || it.has_sauce || it.has_supplements !== false || it.has_menu_addon || it.has_frites_style)
                          ? <button onClick={e => { e.stopPropagation(); go('item', it.id); }} aria-label={`Personnaliser ${it.name}`} className="lc-menu-card__go" style={{ width: 44, height: 44, borderRadius: 999, background: 'var(--orange)', color: '#fff', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}><I.Arrow size={14} stroke="#fff" sw={2.4}/></button>
                          : <button onClick={e => { e.stopPropagation(); quickAdd(it); }} aria-label={justAdded === it.id ? `${it.name} ajouté au panier` : `Ajouter ${it.name} au panier`} className={'lc-quick-add' + (justAdded === it.id ? ' is-done' : '')} style={{ width: 44, height: 44, borderRadius: 999, background: 'var(--ink)', color: 'var(--yellow)', border: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}>{justAdded === it.id ? <span className="lc-check-draw" style={{ display: 'flex' }}><I.Check size={16} stroke="#fff" sw={3}/></span> : <I.Plus size={16}/>}</button>}
                      </div>
                    </div>
                  </div>
                ); })}
              </div>
            </div>
          );
        })}
      </div>
      {/* sticky cart bar */}
      {cart.length > 0 && (
        <div className="lc-cartbar" data-testid="menu-cartbar" style={{ position: 'absolute', left: 16, right: 16, bottom: 122, zIndex: 8 }}>
          <button onClick={() => go('cart')} className="lc-btn" aria-label={`Voir le panier — ${cartCount} article${cartCount > 1 ? 's' : ''}, ${cart.reduce((s,i) => s+i.price*i.qty, 0).toFixed(2).replace('.', ',')} €`} style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 56, justifyContent: 'space-between', padding: '0 20px', boxShadow: 'var(--shadow-cta)' }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span key={cartCount} className="lc-cartbar__count" style={{ background: '#fff', color: 'var(--orange)', minWidth: 24, height: 24, padding: '0 6px', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 800 }}>{cartCount}</span>
              Voir le panier
            </span>
            <Bump value={cart.reduce((s,i) => s+i.price*i.qty, 0).toFixed(2)}>{cart.reduce((s,i) => s+i.price*i.qty, 0).toFixed(2).replace('.', ',')} €</Bump>
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

const FALLBACK_FORMULA_IMAGES = {
  'f-menu': 'assets/menu/menu_complet.png',
  'f-frites': 'assets/menu/frites.png',
  'f-boisson': 'assets/menu/boisson.png',
};

// ScreenItem — thin wrapper delegating to the multi-page wizard
// (mobile/screens-item-steps.jsx exposes window.ScreenItemWizard).
// Refactor 2026-05-10 post-audit cross-agent. Single-scroll legacy preserved
// only as fallback if the wizard module fails to load.
function ScreenItem({ go, itemId, addToCart }) {
  const screenRef = useScreenScrollReset(itemId);
  if (typeof window.ScreenItemWizard === 'function') {
    return <window.ScreenItemWizard go={go} itemId={itemId} addToCart={addToCart}/>;
  }
  // Fallback : single-scroll (kept for safety if wizard module fails to load)
  const lcMenu = window.LC.menu;
  const item = lcMenu.findItem(itemId);
  if (!item) {
    return (
      <div ref={screenRef} data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff', padding: 40, paddingTop: 100, textAlign: 'center' }}>
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
    <div ref={screenRef} data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 110, paddingTop: 0 }}>
        {/* hero photo */}
        <div style={{ position: 'relative', height: 280, background: 'var(--ink)' }}>
          <Slot id={item.thumb} h="100%" radius={0} placeholder={item.name} src={item.image} alt={item.name} fit="contain"/>
          <div style={{ position: 'absolute', top: 'calc(var(--ios-safe-top) - 14px)', left: 14, right: 14, display: 'flex', justifyContent: 'space-between', zIndex: 2 }}>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Retour"><I.Back size={20}/></IconBtn>
            <div style={{ display: 'flex', gap: 8 }}>
              <span aria-hidden="true" style={{ width: 44, height: 44 }}/>
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
          <AllergenBadge allergens={item.allergens} size="lg"/>

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
                        {m.image && <img
                          className="lc-wizard-choice-image lc-wizard-choice-image--meat"
                          src={m.image}
                          alt=""
                          aria-hidden="true"
                          onError={(e) => { e.currentTarget.dataset.imageError = 'true'; }}
                        />}
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
                      <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 8 }}>
                        {f.id && FALLBACK_FORMULA_IMAGES[f.id]
                          ? <img
                              className="lc-wizard-choice-image"
                              src={FALLBACK_FORMULA_IMAGES[f.id]}
                              alt=""
                              aria-hidden="true"
                              onError={(e) => { e.currentTarget.dataset.imageError = 'true'; }}
                            />
                          : (!f.id && window.NeutralAsset
                            ? <window.NeutralAsset label="Sans formule — aucune formule sélectionnée" style={{ width: 44, height: 44, borderRadius: 10, flexShrink: 0 }} />
                            : null)}
                        {f.name}
                      </span>
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
            if (formuleLabel) summaryParts.push(formuleLabel);

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
  const screenRef = useScreenScrollReset('cart');
  // [2026-11-14 B-001 P0] Code promo local SUPPRIMÉ : la remise -10 % n'existait que dans l'app,
  // le serveur (seule source du prix) ne la recevait jamais → total affiché ≠ total encaissé.
  // Le total du panier est désormais strictement la somme des lignes.
  const total = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const articleCount = cart.reduce((s, i) => s + (Number(i.qty) || 0), 0);
  // [GOAL-SYNC 2026-07-08] Earn RÉEL (contrat §2) : 1 pt/€ FLOOR du total TTC, ratio lu depuis
  // la config alignée backend (lcEarnRatio, plus de 10 pt/€ hardcodé). La « prochaine
  // récompense » du catalogue mock est remplacée par le modèle continu points→€.
  const earnRatio = lcEarnRatio();
  const redeemRatio = lcRedeemRatio();
  const pointsForCart = Math.floor((total || 0) * earnRatio);
  const updateQty = (idx, d) => { if (window.lcHaptic) window.lcHaptic(6); setCart(c => c.map((it, i) => i === idx ? { ...it, qty: Math.max(1, it.qty + d) } : it)); };
  const remove = (idx) => setCart(c => c.filter((_, i) => i !== idx));
  return (
    <div ref={screenRef} data-screen-label="10 Cart" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 200 }}>
        <ScreenHeader left={<IconBtn onClick={() => go('back')} ariaLabel="Retour"><I.Back size={20}/></IconBtn>} center={<div style={{ fontSize: 13, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>PANIER</div>}/>
        <div style={{ padding: '8px 20px 0' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Ta<br/>commande</h1>
          <div style={{ marginTop: 8, fontSize: 13, color: 'var(--gray-3)' }}>{articleCount} article{articleCount>1?'s':''} · à retirer au comptoir</div>
        </div>
        {/* items */}
        <div style={{ padding: '20px 20px 0', display: 'grid', gap: 12 }}>
          {cart.length === 0 ? (
            <div className="lc-empty" role="status">
              <div className="lc-empty__art" aria-hidden="true"><I.Bag size={30} stroke="var(--yellow)"/></div>
              <div className="lc-display" style={{ fontSize: 24, lineHeight: 1 }}>Ton panier est vide</div>
              <div style={{ fontSize: 13, color: 'var(--gray-4)', marginTop: 8 }}>Choisis un plat sur la carte, personnalise-le et il arrive ici.</div>
              <button onClick={() => go('menu')} className="lc-btn lc-btn--ink" style={{ height: 48, marginTop: 16, fontSize: 13 }}>Voir la carte <I.Arrow size={16} stroke="var(--yellow)"/></button>
            </div>
          ) : cart.map((it, idx) => (
            <div key={idx} className={(it._addedAt && Date.now() - it._addedAt < 2500) ? 'lc-line-new' : ''} data-testid="cart-line" style={{ display: 'flex', gap: 12, padding: 12, background: 'var(--cream)', borderRadius: 16 }}>
              <div style={{ width: 80, height: 80, borderRadius: 12, overflow: 'hidden', flexShrink: 0, background: 'var(--ink)' }}>
                <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name} fit="contain"/>
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
                    <span className="lc-stepper-val" aria-live="polite" aria-atomic="true"><Bump value={it.qty}/></span>
                    <button onClick={() => updateQty(idx, +1)} aria-label={`Augmenter la quantité de ${it.name}`}>+</button>
                  </div>
                  <Bump value={(it.price*it.qty).toFixed(2)} style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--orange-text)' }}>{(it.price*it.qty).toFixed(2).replace('.', ',')} €</Bump>
                </div>
              </div>
              <button onClick={() => remove(idx)} aria-label={`Retirer ${it.name} du panier`} style={{ background: 'transparent', border: 0, color: 'var(--gray-3)', cursor: 'pointer', minWidth: 44, minHeight: 44, alignSelf: 'flex-start', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><I.Trash size={16}/></button>
            </div>
          ))}
        </div>
        {/* loyalty banner — points + prochaine récompense dérivés de LC.loyalty ([UI/UX 2026-11-15] rien à créditer sur un panier vide) */}
        {cart.length > 0 && <div style={{ margin: '20px 20px 0', padding: 16, background: 'var(--yellow)', borderRadius: 16, display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 40, height: 40, borderRadius: 10, background: 'var(--ink)', color: 'var(--orange)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><I.Gift size={20}/></div>
          <div style={{ flex: 1 }}>
            {/* [2026-11-14 B-004] Règle propriétaire : les points sont crédités quand le restaurant
                VALIDE la commande — jamais « gagnés » au passage en caisse. */}
            <div style={{ fontSize: 13, fontWeight: 700 }}>Environ {pointsForCart} pts à créditer dès que le restaurant valide ta commande</div>
            {/* [GOAL-SYNC 2026-07-08] Modèle continu points→€ (contrat §2) — plus de nom de récompense mock */}
            <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>Réductions en caisse dès {lcMinRedeem()} pts ({redeemRatio} pts = 1 €)</div>
          </div>
        </div>}
        {/* upsell */}
        <div style={{ marginTop: 24 }}>
          <div className="lc-sec-title">
            <h3>Pour accompagner ?</h3>
            <span className="more">Notre conseil</span>
          </div>
          <div style={{ display: 'flex', gap: 10, padding: '0 20px', overflowX: 'auto' }}>
            {/* [GOAL-SYNC 2026-07-08] FIX P1 : slugs 'sides'/'drinks' inexistants (les slugs réels
                de data/menu.js sont 'frites'/'boissons'/'desserts') → l'upsell n'affichait que les
                desserts. Mix garanti frites + boissons + desserts (fixture canonique = loi). */}
            {[
              // [2026-11-14] jamais un produit épuisé en suggestion (B-003 / R2).
              ...ITEMS.filter(i => i.cat === 'frites' && i.available !== false).slice(0, 2),
              ...ITEMS.filter(i => i.cat === 'boissons' && i.available !== false).slice(0, 3),
              ...ITEMS.filter(i => i.cat === 'desserts' && i.available !== false).slice(0, 2),
            ].map(it => (
              <div key={it.id} onClick={() => go('item', it.id)} style={{ flex: '0 0 130px', background: 'var(--cream)', borderRadius: 12, padding: 8, cursor: 'pointer' }}>
                <div style={{ height: 80, borderRadius: 8, overflow: 'hidden', background: 'var(--ink)' }}>
                  <Slot id={it.slot} h="100%" radius={0} placeholder={it.name} src={it.image} alt={it.name} fit="contain"/>
                </div>
                <div style={{ marginTop: 6, fontSize: 12, fontWeight: 700, lineHeight: 1.2 }}>{it.name}</div>
                <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <span style={{ fontSize: 11, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{it.price.toFixed(2).replace('.', ',')} €</span>
                  <button onClick={(e) => { e.stopPropagation(); go('item', it.id); }} aria-label={`Ajouter ${it.name}`} style={{ width: 44, height: 44, borderRadius: 999, background: 'var(--orange)', color: '#fff', border: 0, fontSize: 18, fontWeight: 700, cursor: 'pointer', flexShrink: 0 }}>+</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
      {/* sticky checkout */}
      {cart.length > 0 && (
        <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', padding: '14px 20px 36px', borderTop: '1px solid var(--gray-1)' }}>
          {/* [2026-11-14 B-001 P0] Plus de champ code promo : TOTAL = somme des lignes (le serveur recalcule). */}
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 4 }}>
            <div>
              <div style={{ fontSize: 11, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>Total</div>
              <div data-testid="cart-total" className="lc-display" style={{ fontSize: 36, lineHeight: 1 }}><Bump value={total.toFixed(2)}>{total.toFixed(2).replace('.', ',')} €</Bump></div>
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
  const screenRef = useScreenScrollReset('confirm');
  // Fallback safe values if order missing (e.g. direct nav without prior pay step)
  const orderId = (order && order.id) || 'C-0000';
  const orderTotal = (order && typeof order.total === 'number') ? order.total : 0;
  const orderEta = (order && order.eta) || '—';
  // [GOAL-SYNC 2026-07-08] N° d'appel RÉEL : queue_number posé par index.html (placeCounterOrder,
  // commande réelle caisse). Présent → affiché en grand ; absent → comportement local V0 inchangé.
  const queueNo = (order && order.queue_number != null && String(order.queue_number).trim()) ? String(order.queue_number).trim() : null;
  // [GOAL-SYNC-HEAL 2026-07-08] Honnêteté hors-ligne : quand placeCounterOrder (index.html) est
  // tombé en dégradation locale, l'order porte offline:true (queue_number:null). On ne présente
  // alors PAS la commande comme transmise à la cuisine — bandeau clair + libellé qualifié.
  const isOffline = !!(order && order.offline);
  return (
    <div ref={screenRef} data-screen-label="11 Confirmation" style={{ position: 'absolute', inset: 0, background: '#FFD93D', display: 'flex', flexDirection: 'column', paddingTop: 'var(--ios-safe-top)' }}>
      <ScreenHeader left={<IconBtn bg="var(--ink)" color="#fff" onClick={() => go('home')} ariaLabel="Fermer la confirmation"><I.Close size={18}/></IconBtn>} center={<Logo size={12}/>}/>
      <div style={{ flex: 1, padding: '4px 20px 20px', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
        {isOffline && (
          <div role="status" data-testid="confirm-offline-banner" style={{ margin: '0 0 10px', background: 'var(--red)', color: '#fff', borderRadius: 12, padding: '10px 14px', fontSize: 12, fontWeight: 700, textAlign: 'center' }}>
            Hors ligne — commande NON transmise. À repasser sur place.
          </div>
        )}
        <div style={{ textAlign: 'center' }}>
          {/* [UI/UX 2026-11-15] Une seule séquence : la coche se dessine, le titre monte, le ticket suit. */}
          <div className="lc-confirm__check" style={{ width: 64, height: 64, borderRadius: 999, background: 'var(--ink)', color: 'var(--orange)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxShadow: '4px 4px 0 var(--paper)' }}>
            <I.Check size={32} sw={3}/>
          </div>
          <h1 className="lc-display lc-confirm__title" style={{ margin: '10px 0 0', fontSize: 38, lineHeight: 0.9 }}>{isOffline ? 'Presque !' : "C'est parti !"}</h1>
          <p className="lc-confirm__title" style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--ink)' }}>{isOffline ? <>Brouillon <b>#{orderId}</b> — à finaliser en caisse</> : <>Commande <b>#{orderId}</b> envoyée</>}</p>
        </div>
        {/* QR ticket card */}
        <div className="lc-confirm__ticket" style={{ marginTop: 12, background: '#fff', borderRadius: 18, padding: 14, position: 'relative' }}>
          <div style={{ position: 'absolute', top: 44, left: -6, width: 12, height: 12, borderRadius: 999, background: 'var(--yellow)' }}/>
          <div style={{ position: 'absolute', top: 44, right: -6, width: 12, height: 12, borderRadius: 999, background: 'var(--yellow)' }}/>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
            <div className="lc-eyebrow" style={{ color: 'var(--gray-3)', fontSize: 9 }}>Retrait au comptoir</div>
            <div style={{ fontFamily: 'var(--font-mono)', fontSize: 9, color: 'var(--gray-3)' }}>HÉNIN-BEAUMONT · 62110</div>
          </div>
          {/* [2026-11-14 B-009] Le QR décoratif (QRMock, motif pseudo-aléatoire non scannable) est
              SUPPRIMÉ : la caisse appelle le client par son numéro d'appel, affiché en grand. */}
          {/* [GOAL-SYNC 2026-07-08] queue_number réel (commande caisse) affiché en grand */}
          <div style={{ borderTop: '1.2px dashed var(--gray-2)', paddingTop: 12 }}>
            {queueNo && (
              <div style={{ textAlign: 'center', fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase' }}>Ton numéro d'appel</div>
            )}
            <div data-testid="confirm-queue-number" className="lc-confirm__num" style={{ textAlign: 'center', fontFamily: 'var(--font-display)', fontSize: queueNo ? 64 : 24, lineHeight: 1, letterSpacing: '0.06em', marginTop: 4 }}>{queueNo || orderId}</div>
          </div>
          {queueNo && queueNo !== orderId && (
            <div style={{ textAlign: 'center', fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--gray-3)', marginTop: 2 }}>Commande #{orderId}</div>
          )}
          <div style={{ borderTop: '1.2px dashed var(--gray-2)', marginTop: 10, paddingTop: 8, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6 }}>
            <div>
              <div style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Paiement</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18 }}>En caisse</div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 9, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Total</div>
              <div style={{ fontFamily: 'var(--font-display)', fontSize: 18, color: 'var(--orange)' }}>{orderTotal.toFixed(2).replace('.', ',')} €</div>
            </div>
          </div>
        </div>
        {/* [2026-11-14 B-014] Plus de « EN PRÉPARATION ~12 MIN » ni de barre à moitié pleine codés en
            dur : la commande vient d'être ENVOYÉE, le restaurant ne l'a pas encore validée. */}
        {!isOffline && (
          <div data-testid="confirm-status" className="lc-confirm__after" style={{ marginTop: 10, padding: 12, background: 'var(--ink)', borderRadius: 12, color: '#fff', fontSize: 12, lineHeight: 1.45 }}>
            <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--yellow)', marginBottom: 4 }}>Envoyée au restaurant</div>
            Le restaurant va valider ta commande. Suis son avancement dans « Commandes ».
          </div>
        )}
        <div className="lc-confirm__after" style={{ marginTop: 'auto', display: 'flex', gap: 8, paddingTop: 10 }}>
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
// [GOAL-SYNC 2026-07-08] Mapping ligne backend GET /api/frontend/order → shape d'affichage
// mobile. Sémantique regex statuts FR PORTÉE du web (orders.jsx:14-28) :
// annul* → annulée · terminé/livré/récupéré/complet/prêt/servi → livrée · sinon → en cours.
function mapRemoteOrderRow(o) {
  o = o || {};
  const sn = String(o.status_name || '').toLowerCase();
  const status = /annul/.test(sn) ? 'cancelled'
    : /(termin|livr|récup|recup|complet|prêt|pret|servi)/.test(sn) ? 'delivered'
    : 'pending';
  // [2026-11-14 B-022] nombre d'ARTICLES = somme des quantités (order_items_quantity), pas des lignes
  const count = Number(o.order_items_quantity) || Number(o.order_items) || 0;
  return {
    id: o.order_serial_no || ('#' + (o.id != null ? o.id : '—')),
    dbId: o.id != null ? o.id : null,
    // [2026-11-14 B-005] Le numéro d'appel (A0032) est celui de l'écran de confirmation et celui que
    // la caisse annonce : c'est l'identifiant principal de la carte ; le n° de série reste secondaire.
    queue_number: (o.queue_number != null && String(o.queue_number).trim()) ? String(o.queue_number).trim() : null,
    // Points RÉELLEMENT crédités par le serveur (net) — jamais une estimation présentée comme acquise.
    points_awarded: Number(o.loyalty_points_awarded) || 0,
    date: o.order_datetime || '',
    total: parseFloat(o.total_amount_price || 0) || 0,
    status,
    status_label: o.status_name || (status === 'delivered' ? 'Récupérée' : status === 'cancelled' ? 'Annulée' : 'En préparation'),
    items_summary: count > 0 ? (count + ' article' + (count > 1 ? 's' : '')) : '',
    remote: true,
  };
}

function ScreenOrders({ go }) {
  const screenRef = useScreenScrollReset('orders');
  const [tab, setTab] = uS('current');
  // [GOAL-SYNC 2026-07-08] Historique RÉEL : si connecté → LC.mobileApi.orderHistory(50) ;
  // réseau down → fallback data layer locale + bandeau hors-ligne ; erreur métier → message FR.
  const api = (window.LC && window.LC.mobileApi) || null;
  const authed = !!(api && api.isAuthed && api.isAuthed() && api.orderHistory);
  const [remote, setRemote] = uS(authed ? 'loading' : null); // 'loading' | {active,history} | null = mode local
  const [offline, setOffline] = uS(false);
  const [remoteErr, setRemoteErr] = uS(null);
  // [UI/UX 2026-11-15] Relecture toutes les 20 s tant que l'écran est ouvert : le statut change sous les yeux
  // du client sans qu'il ait à quitter l'écran (une erreur de relecture garde la liste précédente).
  uE(() => {
    if (!authed) return;
    let alive = true;
    const charger = (premiere) => api.orderHistory(50).then(rows => {
      if (!alive) return;
      const mapped = (Array.isArray(rows) ? rows : []).map(mapRemoteOrderRow);
      setRemote({
        active: mapped.filter(o => o.status === 'pending'),
        history: mapped.filter(o => o.status !== 'pending'),
      });
      setOffline(false); setRemoteErr(null);
    }).catch(e => {
      if (!alive || !premiere) return;
      setRemote(null);
      if (e && e.kind === 'network') setOffline(true);
      else setRemoteErr((e && e.message) || 'Chargement des commandes impossible.');
    });
    charger(true);
    const t = setInterval(() => { if (document.visibilityState !== 'hidden') charger(false); }, 20000);
    return () => { alive = false; clearInterval(t); };
  }, []);
  const remoteMode = remote !== null && remote !== 'loading';
  const loading = remote === 'loading';
  const ordersApi = (window.LC && window.LC.orders) ? window.LC.orders : { active: [], history: [] };
  const active = remoteMode ? remote.active : (Array.isArray(ordersApi.active) ? ordersApi.active : []);
  const history = remoteMode ? remote.history : (Array.isArray(ordersApi.history) ? ordersApi.history : []);
  // [2026-11-14 B-010] Plus de tuile « Dépensé » (règle propriétaire : jamais la dépense du client).
  // [2026-11-14 B-006] Les commandes serveur ouvrent la fiche détail (GET /api/frontend/order/show/{id}).
  const detailOpener = (o) => {
    if (o.remote) return o.dbId != null ? (() => go('orderDetail', 'srv-' + o.dbId)) : undefined;
    return () => go('orderDetail', o.id);
  };
  const groupByDate = (!remoteMode && ordersApi.groupByDate && typeof ordersApi.groupByDate === 'function')
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
    <div ref={screenRef} data-screen-label="12 Orders" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
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
        {/* [GOAL-SYNC 2026-07-08] États réseau FR : chargement / hors-ligne / erreur */}
        {loading && (
          <div role="status" aria-label="Chargement de tes commandes" style={{ margin: '20px 20px 0', display: 'grid', gap: 12 }}>
            <div className="lc-skeleton lc-shimmer" style={{ height: 150, borderRadius: 20 }}/>
            <div className="lc-skeleton lc-shimmer" style={{ height: 150, borderRadius: 20, opacity: .6 }}/>
            <span className="lc-sr-only">Chargement de tes commandes…</span>
          </div>
        )}
        {offline && (
          <div role="status" data-testid="orders-offline-banner" style={{ margin: '14px 20px 0', background: 'var(--yellow)', borderRadius: 12, padding: '10px 14px', fontSize: 12, fontWeight: 700, color: 'var(--ink)' }}>
            📡 Hors ligne — historique local affiché
          </div>
        )}
        {remoteErr && (
          <div role="alert" style={{ margin: '14px 20px 0', background: 'var(--cream)', border: '1.5px solid var(--red)', borderRadius: 12, padding: '10px 14px', fontSize: 12, fontWeight: 600, color: 'var(--red)' }}>
            {remoteErr} — historique local affiché.
          </div>
        )}
        {!loading && (tab === 'current' ? (
          <div style={{ padding: '20px 20px 0' }}>
            {active.length === 0 && (
              <div role="status" className="lc-empty">
                <div className="lc-empty__art" aria-hidden="true"><I.Receipt size={30} stroke="var(--yellow)"/></div>
                <div className="lc-display" style={{ fontSize: 22, lineHeight: 1 }}>Aucune commande en cours</div>
                <div style={{ fontSize: 13, color: 'var(--gray-4)', marginTop: 8 }}>Ta prochaine commande s'affichera ici, avec son numéro d'appel.</div>
                <button onClick={() => go('menu')} className="lc-btn lc-btn--ink" style={{ height: 48, marginTop: 16, fontSize: 13 }}>Voir la carte <I.Arrow size={16} stroke="var(--yellow)"/></button>
              </div>
            )}
            {active.map((o, idxCard) => {
              const summary = o.items_summary || (Array.isArray(o.items) ? o.items.map(i => i.name).join(' · ') : '');
              const statusLabel = (o.status_label || 'En préparation').toUpperCase();
              // [2026-11-14 B-014] Plus d'ETA « ~12 MIN » ni de barre de progression par défaut : seul le
              // statut serveur est affiché. [B-005] Le numéro d'appel (même que la confirmation) en grand.
              const callNo = o.queue_number || null;
              const openDetail = detailOpener(o);
              return (
                /* [test-e2e fix D-004 round-2 2026-05-11] active order routes to detail, not confirm */
                <div key={o.id} data-testid={'orders-active-card-' + o.id} role={openDetail ? 'button' : undefined} tabIndex={openDetail ? 0 : undefined} aria-label={(callNo ? `Commande ${callNo}` : `Commande ${o.id}`) + ' en cours' + (openDetail ? ' — voir détails' : '')} className="lc-tap" onClick={openDetail} onKeyDown={openDetail ? window.lcTapKey(openDetail) : undefined} style={{ background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 20, position: 'relative', overflow: 'hidden', cursor: openDetail ? 'pointer' : 'default', marginBottom: 12 }}>
                  <div style={{ position: 'absolute', top: -20, right: -20, width: 140, height: 140, opacity: 0.06 }}><I.Pepper size={140} stroke="#FF5A1F"/></div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <span className="lc-pill" style={{ background: 'var(--orange)', color: '#fff' }}><span className={'lc-status-dot' + (idxCard < 3 ? ' lc-live-dot' : '')} style={{ background: '#fff', color: '#fff' }}/> {statusLabel}</span>
                    {callNo && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'rgba(255,255,255,0.6)' }}>#{o.id}</span>}
                  </div>
                  {callNo && <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'rgba(255,255,255,0.6)', marginTop: 14 }}>Numéro d'appel</div>}
                  <div data-testid="orders-card-call-number" className="lc-display" style={{ fontSize: 36, marginTop: callNo ? 2 : 14, color: 'var(--yellow)' }}>{callNo || ('#' + o.id)}</div>
                  <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>{summary}</div>
                  <div style={{ marginTop: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 14, borderTop: '1px solid rgba(255,255,255,0.1)' }}>
                    <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--yellow)' }}>{openDetail ? 'Voir le détail →' : (o.date || 'Commande en cours')}</span>
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
                {/* [GOAL-SYNC 2026-07-08] header de groupe masqué en mode API (date portée par chaque carte) */}
                {g.date ? <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--orange)', letterSpacing: '0.22em', marginBottom: 8, paddingLeft: 4 }}>● {dateLabel(g.date)}</div> : null}
                <div style={{ display: 'grid', gap: 8 }}>
                  {g.items.map(o => {
                    const summary = o.items_summary || (Array.isArray(o.items) ? o.items.map(i => i.name).join(' · ') : '');
                    // [2026-11-14 B-004] Seuls les points RÉELLEMENT crédités (serveur) sont affichés — plus
                    // d'estimation présentée comme acquise.
                    const points = o.remote ? (Number(o.points_awarded) || 0) : (Number(o.points_earned) || 0);
                    const statusLabel = o.status_label || 'Récupérée';
                    const isCancelled = o.status === 'cancelled';
                    const openDetail = detailOpener(o);
                    return (
                      <div key={o.id} data-testid={'orders-history-card-' + o.id} role={openDetail ? 'button' : undefined} tabIndex={openDetail ? 0 : undefined} aria-label={(o.queue_number ? `Commande ${o.queue_number}` : `Commande ${o.id}`) + (openDetail ? ' — voir détails' : '')} onClick={openDetail} onKeyDown={openDetail ? window.lcTapKey(openDetail) : undefined} style={{ background: 'var(--cream)', borderRadius: 14, padding: 14, position: 'relative', cursor: openDetail ? 'pointer' : 'default' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4, flexWrap: 'wrap' }}>
                              {o.queue_number ? <span style={{ fontFamily: 'var(--font-display)', fontSize: 14, color: 'var(--ink)' }}>{o.queue_number}</span> : null}
                              <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--gray-3)' }}>#{o.id}</span>
                              <span style={{ width: 4, height: 4, borderRadius: 999, background: 'var(--gray-2)' }}/>
                              <span style={{ fontSize: 10, fontWeight: 700, color: isCancelled ? 'var(--red)' : 'var(--green)', textTransform: 'uppercase', letterSpacing: '0.08em' }}>{isCancelled ? '✕' : '✓'} {statusLabel}</span>
                              {o.remote && o.date ? <span style={{ fontSize: 10, color: 'var(--gray-3)' }}>{o.date}</span> : null}
                            </div>
                            <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.3 }}>{summary}</div>
                            <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }}>
                              <span style={{ fontFamily: 'var(--font-display)', fontSize: 22, color: 'var(--orange)' }}>{fmtEur(o.total)}</span>
                              {points > 0 ? <span style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 600 }}>+{points} pts crédités</span> : null}
                            </div>
                          </div>
                          <button onClick={(e) => { e.stopPropagation(); go('menu'); }} onKeyDown={(e) => e.stopPropagation()} style={{ background: 'var(--ink)', color: 'var(--yellow)', border: 0, padding: '10px 14px', minHeight: 44, borderRadius: 999, fontSize: 10, fontWeight: 700, cursor: 'pointer', textTransform: 'uppercase', letterSpacing: '0.08em', flexShrink: 0, alignSelf: 'center' }}>↻ Refaire</button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

// PROFILE
// [GOAL-SYNC 2026-07-08] Identité RÉELLE : nom/téléphone via LC.mobileApi.profile() (GET
// /api/profile, contrat §2) quand connecté ; fallback storage.getAuth().phone sinon.
// Le « Ikyes B. / +33 6 42 79 98 84 » hardcodé est SUPPRIMÉ.
function ScreenProfile({ go }) {
  const screenRef = useScreenScrollReset('profile');
  const api = (window.LC && window.LC.mobileApi) || null;
  const authed = !!(api && api.isAuthed && api.isAuthed());
  const storedAuth = (window.LC && window.LC.storage && window.LC.storage.getAuth) ? (window.LC.storage.getAuth() || {}) : {};
  const [prof, setProf] = uS(null);
  uE(() => {
    if (!authed || !api.profile) return;
    let alive = true;
    api.profile().then(p => { if (alive) setProf(p || null); }).catch(() => { /* fallback storage silencieux */ });
    return () => { alive = false; };
  }, []);
  const rawName = (prof && (prof.name || [prof.first_name, prof.last_name].filter(Boolean).join(' '))) || '';
  const displayName = lcHumanName(rawName) || 'Client Le Cayenne';
  const displayPhone = (prof && prof.phone) || storedAuth.phone || '—';
  const { display: branchPhoneDisplay, href: branchPhoneHref } = branchPhoneInfo();
  const initials = displayName.split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase() || 'LC';
  return (
    <div ref={screenRef} data-screen-label="13 Profile" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 100 }}>
        <div style={{ padding: '16px 20px 0' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 52, lineHeight: 0.9 }}>Profil</h1>
        </div>
        {/* user card */}
        <div style={{ padding: '20px 20px 0' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14, padding: 16, background: 'var(--cream)', borderRadius: 16 }}>
            <div style={{ width: 56, height: 56, borderRadius: 999, background: 'var(--orange)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 24 }}>{initials}</div>
            <div style={{ flex: 1 }}>
              <div data-testid="profile-name" style={{ fontSize: 17, fontWeight: 700 }}>{displayName}</div>
              <div data-testid="profile-phone" style={{ fontSize: 12, color: 'var(--gray-3)', fontFamily: 'var(--font-mono)' }}>{displayPhone}</div>
            </div>
            {/* [2026-11-14 B-008] Bouton « MODIFIER » (toast « bientôt disponible ») retiré : aucune édition n'est branchée. */}
          </div>
        </div>
        {/* loyalty preview card — [GOAL-SYNC 2026-07-08] modèle CONTINU points→€ (contrat §2) :
            plus de « prochaine récompense » du catalogue mock. Solde réel du profil si dispo. */}
        {(() => {
          const lAcc = (window.LC.loyalty && window.LC.loyalty.account) || { balance: 0 };
          const lBalance = (prof && prof.loyalty_points != null) ? (Number(prof.loyalty_points) || 0) : (Number(lAcc.balance) || 0);
          const lRatio = lcRedeemRatio();
          const lMin = lcMinRedeem();
          const lEuros = lBalance >= lMin ? Math.floor(lBalance / lRatio) : 0;
          const lProgPct = Math.min(100, Math.round((lBalance / lMin) * 100));
          return (
            <div style={{ padding: '14px 20px 0' }}>
              <div role="button" tabIndex={0} aria-label={`Carte fidélité — ${lBalance} points, voir détails`} className="lc-tap" onClick={() => go('loyalty')} onKeyDown={window.lcTapKey(() => go('loyalty'))} style={{ position: 'relative', background: 'var(--ink)', color: '#fff', borderRadius: 20, padding: 20, overflow: 'hidden', cursor: 'pointer' }}>
                <div style={{ position: 'absolute', top: -30, right: -30, width: 180, height: 180, borderRadius: 999, background: 'var(--orange)', opacity: 0.42 }}/>
                <div style={{ position: 'absolute', top: -10, right: -10, width: 100, height: 100, borderRadius: 999, background: 'var(--yellow)', opacity: 0.30 }}/>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                  <I.Gift size={18} stroke="var(--yellow)"/>
                  <span className="lc-eyebrow" style={{ color: 'var(--yellow)' }}>Carte fidélité</span>
                </div>
                <div className="lc-display" style={{ fontSize: 56, lineHeight: 0.9 }}>{lBalance}<span style={{ fontSize: 18, color: 'var(--orange)' }}> PTS</span></div>
                <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>
                  {lEuros > 0
                    ? <>Soit <b style={{ color: 'var(--yellow)' }}>{lEuros} €</b> de réduction en caisse · {lRatio} pts = 1 €</>
                    : <>Plus que <b style={{ color: 'var(--orange)' }}>{lMin - lBalance} pts</b> pour ta première réduction ({lMin} pts = {Math.floor(lMin / lRatio)} €)</>}
                </div>
                <div style={{ marginTop: 14, height: 6, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
                  <ProgressFill pct={lProgPct}/>
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
              // [2026-11-14 B-008] Lignes factices retirées (Notifications « Activées », Allergènes « 2 actives »,
              // Langue « Français » : valeurs inventées + toast « bientôt disponible »). Le paiement devient
              // une simple information, pas un bouton.
              { i: I.Card, t: 'Paiement : en caisse, CB ou espèces', info: true },
              { i: I.Phone, t: 'Nous contacter', d: branchPhoneDisplay, href: branchPhoneHref },
              // [2026-11-14 B-007] Vrais liens légaux (pages vérifiées HTTP 200 sur lecayenne.fr), source
              // unique window.LC_LEGAL (screens-onboarding.jsx), repli sur les mêmes URLs.
              { i: I.Shield, t: 'Politique de confidentialité', href: (window.LC_LEGAL && window.LC_LEGAL.confidentialite) || 'https://www.lecayenne.fr/legal/privacy.html', external: true },
              { i: I.Shield, t: 'Conditions générales de vente', href: (window.LC_LEGAL && window.LC_LEGAL.cgu) || 'https://www.lecayenne.fr/legal/cgv.html', external: true },
              { i: I.Shield, t: 'Mentions légales', href: 'https://www.lecayenne.fr/legal/mentions.html', external: true },
            ].map((row, i) => {
              const content = <>
                <div style={{ width: 36, height: 36, borderRadius: 10, background: row.accent || 'var(--ink)', color: row.accent ? '#fff' : 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center', marginRight: 12 }}><row.i size={16}/></div>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 14, fontWeight: 600 }}>{row.t}</div>
                </div>
                {row.d && <div style={{ fontSize: 12, color: 'var(--gray-3)', marginRight: 10 }}>{row.d}</div>}
                {!row.info && <I.Chevron size={14} stroke="var(--gray-3)"/>}
              </>;
              const label = row.t + (row.d ? ' — ' + row.d : '') + (row.external ? ' (nouvelle fenêtre)' : '');
              if (row.info) {
                return <div key={i} data-testid="profile-payment-info" className="lc-toggle-row">{content}</div>;
              }
              if (row.href) {
                return <a key={i} href={row.href} aria-label={label} target={row.external ? '_blank' : undefined} rel={row.external ? 'noopener' : undefined} className="lc-toggle-row lc-tap" style={{ cursor: 'pointer', color: 'inherit', textDecoration: 'none' }}>{content}</a>;
              }
              return <div key={i} role="button" tabIndex={0} aria-label={label} className="lc-toggle-row lc-tap" style={{ cursor: 'pointer' }} onClick={() => go(row.go)} onKeyDown={window.lcTapKey(() => go(row.go))}>{content}</div>;
            })}
          </div>
          {authed && (
            <button
              data-testid="delete-account-btn"
              onClick={() => go('deleteAccount')}
              aria-label="Supprimer mon compte"
              style={{ width: '100%', marginTop: 18, display: 'flex', alignItems: 'center', gap: 12, padding: '15px 16px', background: '#FFF1F0', color: 'var(--red)', border: '1px solid rgba(201, 54, 47, 0.18)', borderRadius: 14, textAlign: 'left', cursor: 'pointer' }}
            >
              <I.Trash size={18} stroke="var(--red)" />
              <span style={{ flex: 1, fontSize: 13, fontWeight: 800 }}>Supprimer mon compte</span>
              <I.Chevron size={14} stroke="var(--red)" />
            </button>
          )}
          <button onClick={() => go('logout')} style={{ width: '100%', marginTop: 12, background: 'transparent', border: 0, padding: 16, fontSize: 13, fontWeight: 700, color: 'var(--red)', textTransform: 'uppercase', letterSpacing: '0.1em', cursor: 'pointer' }}>Se déconnecter</button>
        </div>
      </div>
    </div>
  );
}

// [GOAL-SYNC 2026-07-08] Panneau RÉDUCTIONS — modèle CONTINU points→€ (contrat §2, décision D6=A).
// Remplace le catalogue mock 8 rewards : 100 pts = 1 €, sélection par multiples de 100
// (min_redeem_points) jusqu'à min(solde, plafond = solde arrondi au multiple inférieur).
// CTA « Utiliser en caisse » → confirmation → POST /api/frontend/loyalty/redeem RÉEL via
// LC.mobileApi.loyaltyRedeem (X-Idempotency-Key auto ; 400/422 remappés FR par api/client.js).
function LoyaltyRedeemPanel({ balance, authed, preset, loyaltyCode, go, onRedeemed }) {
  const redeemRatio = lcRedeemRatio();
  const cfg = lcLoyaltyCfg();
  const minPts = Math.max(redeemRatio, Number(cfg.min_redeem_points) || 1000);
  const safeBalance = Number(balance) || 0;
  const maxPts = Math.floor(safeBalance / redeemRatio) * redeemRatio; // plafond = solde
  const canRedeem = maxPts >= minPts;
  const clampPts = (v) => {
    let p = Math.round((Number(v) || minPts) / redeemRatio) * redeemRatio;
    if (p < minPts) p = minPts;
    if (canRedeem && p > maxPts) p = maxPts;
    return p;
  };
  const [pts, setPts] = uS(clampPts(preset || minPts));
  const [phase, setPhase] = uS('pick'); // pick | confirm | busy | done
  const [err, setErr] = uS(null);
  const [doneEuros, setDoneEuros] = uS(null);
  uE(() => { setPts(clampPts(preset || minPts)); }, [preset, balance]);
  const fmtE = (n) => (Number(n) || 0).toFixed(2).replace('.', ',');
  const euros = pts / redeemRatio;

  const submit = async () => {
    const api = (window.LC && window.LC.mobileApi) || null;
    if (!(api && api.loyaltyRedeem && authed)) {
      setPhase('pick');
      setErr('Connecte-toi pour utiliser tes points en caisse.');
      return;
    }
    setPhase('busy'); setErr(null);
    try {
      let code = String(loyaltyCode || '').trim();
      if (!code && api.profile) {
        try { const p = await api.profile(); code = (p && p.loyalty_code) || ''; } catch (e) { /* géré ci-dessous */ }
      }
      if (!code) throw { kind: 'http', message: 'Carte fidélité introuvable. Réessaie dans un instant.' };
      await api.loyaltyRedeem(code, pts);
      setDoneEuros(euros);
      setPhase('done');
      if (typeof onRedeemed === 'function') onRedeemed();
    } catch (e) {
      setPhase('pick');
      setErr((e && e.message) || 'Utilisation des points impossible. Réessaie.');
    }
  };

  return (
    <div data-testid="redeem-panel" className="rdl-rewards" style={{ padding: 0 }}>
      {/* Règle du modèle continu — toujours visible */}
      <div style={{ background: 'var(--ink)', color: '#fff', borderRadius: 16, padding: 16, textAlign: 'center' }}>
        <div className="lc-display" data-testid="redeem-rule" style={{ fontSize: 28, color: 'var(--yellow)' }}>{redeemRatio} pts = 1 €</div>
        <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>Utilise tes points par tranche de {redeemRatio} pts, dans la limite de ton solde.</div>
      </div>

      {phase === 'done' ? (
        <div role="status" style={{ marginTop: 10, background: '#E8F8ED', border: '1.5px solid var(--green)', borderRadius: 16, padding: 18, textAlign: 'center' }}>
          <div style={{ fontSize: 32 }}>✅</div>
          <div className="lc-display" style={{ fontSize: 22, marginTop: 6 }}>−{fmtE(doneEuros)} € enregistrés</div>
          <div style={{ fontSize: 12, color: 'var(--gray-4)', marginTop: 6 }}>Présente ton QR ou dicte ton numéro de carte en caisse pour en profiter.</div>
          <button onClick={() => setPhase('pick')} className="lc-btn lc-btn--ink" style={{ height: 44, marginTop: 12 }}>OK</button>
        </div>
      ) : !authed ? (
        <div style={{ marginTop: 10, background: 'var(--cream)', borderRadius: 16, padding: 18, textAlign: 'center', fontSize: 13, color: 'var(--gray-4)' }}>
          Connecte-toi pour utiliser tes points en caisse.
        </div>
      ) : !canRedeem ? (
        <div data-testid="redeem-locked" style={{ marginTop: 10, background: 'var(--cream)', borderRadius: 16, padding: 18, textAlign: 'center', fontSize: 13, color: 'var(--gray-4)' }}>
          🔒 Il te manque <b style={{ color: 'var(--ink)' }}>{minPts - safeBalance} pts</b> pour ta première réduction (minimum {minPts} pts).
        </div>
      ) : (
        <div style={{ marginTop: 10, background: 'var(--cream)', borderRadius: 16, padding: 16 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
            <button aria-label={'Retirer ' + redeemRatio + ' points'} disabled={pts <= minPts || phase === 'busy'} onClick={() => setPts(p => clampPts(p - redeemRatio))} style={{ width: 40, height: 40, borderRadius: 999, border: 0, background: pts <= minPts ? 'var(--gray-1)' : 'var(--ink)', color: pts <= minPts ? 'var(--gray-3)' : 'var(--yellow)', fontSize: 18, fontWeight: 800, cursor: pts <= minPts ? 'not-allowed' : 'pointer' }}>−</button>
            <div style={{ textAlign: 'center' }}>
              <div className="lc-display" data-testid="redeem-points-value" style={{ fontSize: 34, lineHeight: 1 }}>{pts} pts</div>
              <div data-testid="redeem-euro-value" style={{ fontSize: 13, fontWeight: 700, color: 'var(--orange)', marginTop: 2 }}>= −{fmtE(euros)} € en caisse</div>
            </div>
            <button aria-label={'Ajouter ' + redeemRatio + ' points'} disabled={pts >= maxPts || phase === 'busy'} onClick={() => setPts(p => clampPts(p + redeemRatio))} style={{ width: 40, height: 40, borderRadius: 999, border: 0, background: pts >= maxPts ? 'var(--gray-1)' : 'var(--orange)', color: pts >= maxPts ? 'var(--gray-3)' : '#fff', fontSize: 18, fontWeight: 800, cursor: pts >= maxPts ? 'not-allowed' : 'pointer' }}>+</button>
          </div>
          <input
            data-testid="redeem-slider"
            type="range"
            min={minPts}
            max={maxPts}
            step={redeemRatio}
            value={pts}
            disabled={phase === 'busy'}
            onChange={(e) => setPts(clampPts(e.target.value))}
            aria-label="Points à utiliser"
            style={{ width: '100%', marginTop: 14, accentColor: 'var(--orange)' }}
          />
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: 'var(--gray-3)', fontWeight: 700 }}>
            <span>{minPts} pts</span>
            <span>{maxPts} pts (solde {safeBalance})</span>
          </div>
          {phase === 'confirm' ? (
            <div style={{ marginTop: 14 }}>
              <div style={{ fontSize: 13, fontWeight: 700, textAlign: 'center' }}>Confirmer : utiliser {pts} pts pour −{fmtE(euros)} € en caisse ?</div>
              <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                <button onClick={() => setPhase('pick')} style={{ flex: 1, height: 48, borderRadius: 999, border: '1.5px solid var(--gray-2)', background: '#fff', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', cursor: 'pointer' }}>Annuler</button>
                <button data-testid="redeem-confirm-btn" onClick={submit} className="lc-btn lc-btn--orange" style={{ flex: 1.4, height: 48, fontSize: 12 }}>Confirmer</button>
              </div>
            </div>
          ) : (
            <button
              data-testid="redeem-cta"
              onClick={() => setPhase('confirm')}
              disabled={phase === 'busy'}
              className="lc-btn lc-btn--ink"
              style={{ width: '100%', height: 52, marginTop: 14, fontSize: 13 }}
            >
              {phase === 'busy' ? 'Validation…' : 'Utiliser en caisse'}
            </button>
          )}
        </div>
      )}
      {err && (
        <div role="alert" data-testid="redeem-error" style={{ marginTop: 8, padding: '10px 12px', background: '#FDECEA', border: '1.5px solid var(--red)', borderRadius: 12, fontSize: 12, fontWeight: 600, color: 'var(--red)' }}>
          {err}
        </div>
      )}
      <div style={{ fontSize: 10, color: 'var(--gray-4)', textAlign: 'center', marginTop: 8, padding: '0 12px' }}>
        ⓘ La réduction est appliquée par l'équipe en caisse au moment de payer.
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
  const screenRef = useScreenScrollReset('loyalty');
  const [tab, setTab] = uS('points');
  const [qrMode, setQrMode] = uS((window.LC.storage && window.LC.storage.getQRPreference) ? window.LC.storage.getQRPreference() : 'qr');
  const [filterSource, setFilterSource] = uS('all');
  const [, force] = uS(0);
  // [GOAL-SYNC 2026-07-08] Données RÉELLES si connecté (contrat §2) : solde via GET /api/profile
  // (loyalty_points — PAS de /balance), historique via GET /loyalty/history. Réseau down ⇒
  // fallback data layer locale + bandeau hors-ligne. refreshSeq relance le fetch post-redeem.
  const api = (window.LC && window.LC.mobileApi) || null;
  const authed = !!(api && api.isAuthed && api.isAuthed());
  const [remoteProfile, setRemoteProfile] = uS(null);
  const [remoteHist, setRemoteHist] = uS(authed && api.loyaltyHistory ? 'loading' : null); // 'loading' | rows[] | null = local
  const [netOffline, setNetOffline] = uS(false);
  const [refreshSeq, setRefreshSeq] = uS(0);
  const [redeemPreset, setRedeemPreset] = uS(null);
  uE(() => {
    if (!authed) return;
    let alive = true;
    if (api.profile) {
      api.profile()
        .then(p => { if (alive) { setRemoteProfile(p || null); setNetOffline(false); } })
        .catch(e => { if (alive && e && e.kind === 'network') setNetOffline(true); });
    }
    if (api.loyaltyHistory) {
      api.loyaltyHistory(1).then(r => {
        if (!alive) return;
        const rows = (r && (r.data || r)) || [];
        setRemoteHist(Array.isArray(rows) ? rows.map((h, i) => ({
          id: 'api-' + i,
          date: (h && h.date) || '',
          type: (h && h.type) || 'earn',
          points: Number(h && h.points) || 0,
          description: (h && h.description) || ((Number(h && h.points) || 0) >= 0 ? 'Points gagnés' : 'Points utilisés'),
          source_surface: (h && h.source_surface) || '',
        })) : []);
      }).catch(e => {
        if (!alive) return;
        setRemoteHist(null);
        if (e && e.kind === 'network') setNetOffline(true);
      });
    }
    return () => { alive = false; };
  }, [refreshSeq]);

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

  // Bind to data layer — zero hardcoded values ; ratios via config RÉELLE (lcLoyaltyCfg)
  const account = window.LC.loyalty.account;
  const historyLoading = remoteHist === 'loading';
  const historyAll = Array.isArray(remoteHist) ? remoteHist : window.LC.loyalty.history;
  const config = lcLoyaltyCfg();
  const earnRatio = lcEarnRatio();
  const redeemRatio = lcRedeemRatio();
  const consent = (window.LC.storage && window.LC.storage.getConsent) ? window.LC.storage.getConsent() : 'opted_in';
  const isOptedOut = consent === 'opted_out';
  const userFirstName = (window.LC.user && window.LC.user.current && window.LC.user.current.first_name) || '';
  const userLastInit = (window.LC.user && window.LC.user.current && window.LC.user.current.last_name) || '';
  const memberDisplayName = lcHumanName(userFirstName) ? (userFirstName + (userLastInit ? ' ' + userLastInit : '')).toUpperCase().trim() : '';

  // Solde RÉEL (profil API) prioritaire ; fallback data layer locale
  const balance = (remoteProfile && remoteProfile.loyalty_points != null)
    ? (Number(remoteProfile.loyalty_points) || 0)
    : (Number(account.balance) || 0);
  const isEmpty = balance === 0 && (Array.isArray(remoteHist) ? remoteHist.length === 0 : (account.lifetime_earned || 0) === 0);
  // [2026-11-14] Utilisable seulement à partir du seuil serveur (min_redeem_points, 1 000) — R2-002.
  const minRedeem = lcMinRedeem();
  const discountValue = balance >= minRedeem ? Math.floor(balance / redeemRatio) : 0;
  const progressMod = Math.min(balance, minRedeem);
  const progressRemaining = balance >= minRedeem ? 0 : (minRedeem - balance);
  const progressPct = Math.round((progressMod / minRedeem) * 100);

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
    <div ref={screenRef} data-screen-label="14 Loyalty" data-testid="loyalty-screen" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 40 }}>
        {/* HERO — yellow top with QR/Barcode + member badge + countdown */}
        <div style={{ background: 'var(--yellow)', padding: '0 0 28px', position: 'relative', overflow: 'hidden' }}>
          <div aria-hidden="true" data-lc-decoration="loyalty-pattern" style={{ position: 'absolute', inset: 0, opacity: 0.08, pointerEvents: 'none', backgroundImage: 'radial-gradient(circle at 18% 22%, rgba(0,0,0,0.5) 0 2px, transparent 2px), radial-gradient(circle at 78% 65%, rgba(0,0,0,0.5) 0 2px, transparent 2px)', backgroundSize: '60px 60px' }}/>
          <ScreenHeader
            left={<IconBtn onClick={() => go('back')} bg="var(--ink)" color="#fff" aria-label="Retour"><I.Back size={20}/></IconBtn>}
            center={<div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase' }}>Carte fidélité</div>}
            right={<button data-testid="qr-mode-toggle" onClick={onQRToggle} aria-label={'Basculer vers ' + (qrMode === 'qr' ? 'code-barres' : 'QR')} style={{ width: 40, height: 40, borderRadius: 999, background: 'var(--ink)', color: '#fff', border: 0, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700, letterSpacing: '0.08em' }}>{qrMode === 'qr' ? 'III' : '▦'}</button>}
          />
          {isOptedOut ? (
            <div style={{ padding: '16px 24px', textAlign: 'center' }}>
              <div className="lc-display" style={{ fontSize: 24, color: 'var(--ink)', marginBottom: 6 }}>Programme désactivé</div>
              <div style={{ fontSize: 13, color: 'var(--ink)', marginBottom: 16 }}>Tu ne cumules plus de points et tes points ont été effacés (RGPD art. 17). Réactive pour t'inscrire à nouveau.</div>
              <button data-testid="loyalty-reactivate-btn" onClick={reactivateLoyaltyAccount} className="lc-btn lc-btn--ink" style={{ height: 48 }}>Réactiver mon compte</button>
            </div>
          ) : (
            <>
              {/* LoyaltyQR card — centered, memoized */}
              <div style={{ display: 'flex', justifyContent: 'center', padding: '8px 20px 0' }}>
                <div style={{ background: '#fff', borderRadius: 22, padding: 18, boxShadow: '0 12px 32px rgba(0,0,0,0.14)' }}>
                  {/* [GOAL-SYNC 2026-07-08] membre RÉEL si profil API chargé (client-N°id) — le
                      mock FK-12345 ne reste QUE comme fallback hors-ligne (pas de faux n° en ligne). */}
                  <window.LoyaltyQR loyaltyCode={(remoteProfile && remoteProfile.loyalty_code) || account.loyalty_code} memberNumber={remoteProfile ? (remoteProfile.id != null ? 'CLIENT-' + remoteProfile.id : '') : account.member_number} name={memberDisplayName} mode={qrMode}/>
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
              {discountValue > 0 ? <>Soit {discountValue},00 € de réduction utilisable en caisse</> : <>Réductions dès {minRedeem} points ({Math.floor(minRedeem / redeemRatio)} € en caisse)</>}
            </div>
            {/* [GOAL-SYNC 2026-07-08] Progression CONTINUE vers le prochain € (contrat §2) */}
            {progressRemaining > 0 && (
              <div style={{ marginTop: 16 }}>
                <div data-testid="loyalty-progress-text" style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: 'rgba(255,255,255,0.7)', marginBottom: 6 }}>
                  <span>{progressMod} / {minRedeem} pts</span>
                  <span style={{ color: 'var(--yellow)' }}>−{Math.floor(minRedeem / redeemRatio)} € EN CAISSE</span>
                </div>
                <div role="progressbar" aria-valuenow={progressMod} aria-valuemax={minRedeem} aria-valuetext={progressMod + ' sur ' + minRedeem + ' points avant la première réduction'} style={{ height: 8, background: 'rgba(255,255,255,0.12)', borderRadius: 999, overflow: 'hidden', position: 'relative' }}>
                  <ProgressFill pct={progressPct}/>
                </div>
                <div style={{ marginTop: 8, fontSize: 12, fontWeight: 600 }}>
                  Plus que <span style={{ color: 'var(--orange)', fontWeight: 800 }}>{progressRemaining} pts</span> pour <b style={{ color: 'var(--yellow)' }}>{Math.floor(minRedeem / redeemRatio)} € de réduction</b> 🎁
                </div>
              </div>
            )}
            {progressRemaining === 0 && balance > 0 && (
              <div style={{ marginTop: 16, padding: 10, background: 'rgba(31,166,83,0.15)', borderRadius: 10, fontSize: 12, fontWeight: 700, color: 'var(--yellow)', textAlign: 'center' }}>
                🎉 {discountValue} € de réduction prêts à être utilisés en caisse
              </div>
            )}
          </div>
        )}

        {/* [2026-11-14 B-012] ACTIONS RAPIDES retirées : les badges officiels Apple/Google Wallet
            n'ouvraient qu'une modale « en cours de provisioning » (badge réservé aux passes réels) et
            « Lier carte plastique » affichait un faux succès sans appareil photo ni appel serveur.
            La carte fidélité RÉELLE reste le QR serveur ci-dessus. */}

        {/* [GOAL-SYNC 2026-07-08] Bandeau hors-ligne (fallback data locale) */}
        {!isOptedOut && authed && netOffline && (
          <div role="status" data-testid="loyalty-offline-banner" style={{ margin: '14px 20px 0', background: 'var(--yellow)', borderRadius: 12, padding: '10px 14px', fontSize: 12, fontWeight: 700, color: 'var(--ink)' }}>
            📡 Hors ligne — données locales affichées
          </div>
        )}

        {/* TABS — Cycle C Wave 4 : rdl-tabs avec bottom indicator on active
            [GOAL-SYNC 2026-07-08] « Récompenses » (catalogue mock) → « Réductions » (points→€ continu) */}
        {!isOptedOut && (
          <div className="rdl-tabs" role="tablist" aria-label="Sections fidélité">
            {[{ id: 'points', label: 'Mes points' }, { id: 'rewards', label: 'Réductions' }, { id: 'history', label: 'Historique' }].map(t => {
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
                {/* [GOAL-SYNC 2026-07-08] earn réel : 1 pt/€ (config backend), plus de bonus mock imposé */}
                <div style={{ fontSize: 13, color: 'var(--gray-4)', marginBottom: 16 }}>Tu n'as pas encore de points. <b>1 € = {earnRatio} pt{earnRatio > 1 ? 's' : ''}</b>, crédités quand le restaurant valide ta commande.</div>
                <button data-testid="cta-first-order" onClick={() => go('menu')} className="lc-btn lc-btn--orange" style={{ height: 48, whiteSpace: 'nowrap', letterSpacing: '0.04em' }}>Commencer ma commande →</button>
                <div style={{ marginTop: 20, fontSize: 11, color: 'var(--gray-4)', textAlign: 'left' }}>
                  <div className="lc-eyebrow" style={{ marginBottom: 6, color: 'var(--ink)' }}>Aperçu des réductions</div>
                  {lcRedeemTiers().map(pts => (
                    <div key={pts} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--gray-1)' }}>
                      <span>🎁 −{Math.floor(pts / redeemRatio)},00 € en caisse</span>
                      <span style={{ fontWeight: 700, color: 'var(--ink)' }}>{pts} pts</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Mes points — paliers indicatifs dérivés de la config RÉELLE (contrat §2 :
                modèle continu points→€, plus AUCUN nom de récompense mock). UTILISER bascule
                sur l'onglet Réductions avec le montant présélectionné. */}
            {tab === 'points' && !isEmpty && (
              <div id="loyalty-tabpanel-points" role="tabpanel" style={{ display: 'grid', gap: 8 }}>
                {(Array.isArray(config.tiers) && config.tiers.length ? config.tiers : [1000, 2000]).map(tier => {
                  const tierEuros = (tier / redeemRatio).toFixed(2).replace('.', ',');
                  const unlocked = balance >= tier;
                  const missing = unlocked ? 0 : tier - balance;
                  return (
                    <div key={tier} data-testid={'reward-row-' + tier} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 14, background: unlocked ? '#E8F8ED' : 'var(--cream)', borderRadius: 14, border: unlocked ? '1.5px solid var(--green)' : '1.5px solid transparent' }}>
                      <div style={{ width: 44, height: 44, borderRadius: 10, background: unlocked ? 'var(--green)' : 'var(--ink)', color: unlocked ? '#fff' : 'var(--yellow)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontSize: 14, flexShrink: 0 }}>{tier}</div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 13, fontWeight: 700 }}>🎁 −{tierEuros} € en caisse</div>
                        <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>{unlocked ? '✓ Disponible' : missing + ' pts manquants'}</div>
                      </div>
                      {unlocked && (
                        <button onClick={() => { setRedeemPreset(tier); setTab('rewards'); }} data-testid={'reward-redeem-btn-' + tier} style={{ background: 'var(--green)', color: '#fff', border: 0, padding: '8px 14px', borderRadius: 999, fontSize: 11, fontWeight: 700, cursor: 'pointer' }}>UTILISER</button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* [GOAL-SYNC 2026-07-08] Réductions — le catalogue mock (8 rewards) est REMPLACÉ
                par le modèle CONTINU points→€ (contrat §2 : 100 pts = 1 €, multiples de 100,
                redeem réel via POST /loyalty/redeem avec gestion 400/422 FR). */}
            {tab === 'rewards' && (
              <div id="loyalty-tabpanel-rewards" role="tabpanel">
                <LoyaltyRedeemPanel
                  balance={balance}
                  authed={authed}
                  preset={redeemPreset}
                  loyaltyCode={(remoteProfile && remoteProfile.loyalty_code) || account.loyalty_code || ''}
                  go={go}
                  onRedeemed={() => setRefreshSeq(s => s + 1)}
                />
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
                  {/* [GOAL-SYNC 2026-07-08] états FR : chargement / vide */}
                  {historyLoading && (
                    <div role="status" style={{ padding: 18, textAlign: 'center', color: 'var(--gray-4)', fontSize: 12 }}>Chargement de l'historique…</div>
                  )}
                  {!historyLoading && filteredHistory.length === 0 && (
                    <div style={{ padding: 18, textAlign: 'center', color: 'var(--gray-4)', fontSize: 12 }}>Aucune transaction pour ce filtre</div>
                  )}
                  {!historyLoading && filteredHistory.map(h => {
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
            {/* [GOAL-SYNC 2026-07-08] Taux dérivés de la config RÉELLE (10 pt/€ · 100 pts = 1 €) */}
            <div style={{ background: 'var(--cream)', borderRadius: 14, padding: 14, fontSize: 12, color: 'var(--ink)', marginBottom: 8 }}>
              1 € = {earnRatio} pt{earnRatio > 1 ? 's' : ''} · {redeemRatio} pts = 1 € de réduction{config.expires_after_days ? ' · Validité ' + config.expires_after_days + ' jours' : ''}
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

Object.assign(window, { ScreenHome, ScreenMenu, ScreenItem, ScreenCart, ScreenConfirm, ScreenOrders, ScreenProfile, ScreenLoyalty, LoyaltyRedeemPanel, ITEMS, CATS, AllergenDisclosure, AllergenBadge });
