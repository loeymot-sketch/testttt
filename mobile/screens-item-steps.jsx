// screens-item-steps.jsx — Wizard multi-page kiosk-aligned
//
// Refactor 2026-05-10 post-audit cross-agent (cf. reports/review/mobile-audit-2026-05-10/).
// Mirrors the kiosk wizard state-machine (KioskWizardComponent.vue activeSteps + currentStep)
// but adapted to a 390×844 mobile viewport with one full-screen step at a time.
//
// Templates (cf. KW.vue:541-622) :
//   tacos     viandes → sauce → crudites → supplements → menu → [cascade] → recap
//   sandwich  viandes? → sauce → crudites → supplements → menu → [cascade] → recap
//   burger    sauce → crudites → supplements → menu → [cascade] → recap
//   assiette  sauce → supplements → recap
//   omelette  sauce → crudites → supplements → recap            (Ojja + Omelettes + Menus Enfants V3.8)
//   salade    sauce → supplements → recap                        (D1 owner-gate: scope simplifié)
//   snacking  sauce → menu → [cascade] → supplements → recap     (wings/tenders)
//   simple    [special cases: frites_style only, sauce only, or direct]
//
// Cascade formule menu (kiosk: KioskStepMenuComponent multi-section single-page;
// mobile: split into 3 separate pages for clarity):
//   menu='full'    → drink + fritesStyle + fritesSauce
//   menu='frites'  → fritesStyle + fritesSauce
//   menu='boisson' → drink
//   menu='none'    → (no cascade)
//
// [GOAL-SYNC 2026-07-08] Alignement canon borne (CONTRACTS.md §5 + fixture
// catalog-canonical.json) — le wizard consomme les nouveaux flags data :
//   - item.has_pain_choice  → étape PAIN radio (pool lcMenu.pains, défaut Pain) avant viandes
//   - item.has_extra_meat   → section « Viande supplémentaire +2,50 € » (multi 0..2)
//                             dans l'étape suppléments → selections.extraMeatIds
//   - template 'bol' (cat 6)→ viande (1/1) → sauce (1/1, pool lcMenu.bolSauces)
//                             → suppléments bols (0/5, sb-gratine riz_only=Bol Riz seul)
//                             → boisson optionnelle (0/1)
//   - sauces tacos/sandwich/burger/bol = 1 max (radio-like), plus AUCUN +0,50 multi-sauce
//   - item.sauce_default    → sauce pré-sélectionnée (ex. Cayenne → Sauce fromagère maison)
//   - suppléments filtrés : galette_only visible seulement cat 2 ; galette_excluded masqué cat 2
//   - prix formule lu depuis FORMULES (f-menu 2,50 €) — une seule source

const { useState: useState_w, useEffect: useEffect_w, useRef: useRef_w } = React;

// ============================================================================
// Step keys (canonical kiosk vocabulary, cf. KW.vue STEP_KEY_REGISTRY:301-325)
// ============================================================================
const STEP = {
  PAIN: 'pain',                      // [GOAL-SYNC 2026-07-08] Pain/Galette (has_pain_choice)
  VIANDES: 'viandes',
  SAUCE: 'sauce',
  CRUDITES: 'crudites',
  SUPPLEMENTS: 'supplements',
  MENU: 'menu',
  DRINK: 'drink',
  FRITES_STYLE: 'fritesStyle',
  FRITES_SAUCE: 'fritesSauce',
  BOL_SUPPLEMENTS: 'bolSupplements', // [MOBILE-REALIGNMENT 2026-05-16] Bols custom composer
  BOL_DRINK: 'bolDrink',             // [MOBILE-REALIGNMENT 2026-05-16] Bols optional drink addon
  RECAP: 'recap',
};

const STEP_LABELS = {
  pain: 'Pain ou galette',
  viandes: 'Viandes',
  sauce: 'Sauce',
  crudites: 'Crudités',
  supplements: 'Suppléments',
  menu: 'Faire un menu',
  drink: 'Boisson',
  fritesStyle: 'Style de frites',
  fritesSauce: 'Sauce frites',
  bolSupplements: 'Suppléments du bol',
  bolDrink: 'Ajouter une boisson',
  recap: 'Récapitulatif',
};

// ============================================================================
// Active-steps computation (mirrors KW.vue computeActiveSteps + V3.8 templates)
// ============================================================================
// [GOAL-SYNC 2026-07-08] Résolution du template partagée (wizard + steps sauce/supp).
function wizardTemplateFor(item) {
  if (!item) return 'simple';
  const cat = window.LC.menu.findCategory(item.category_id);
  // [MOBILE-REALIGNMENT 2026-05-16] item.wizard_template priority over category
  // (kiosk parity: KW.vue:890-895 — item override allowed for special items).
  return item.wizard_template || (cat && cat.wizard_template) || 'simple';
}

// [2026-11-15 relecture adverse P0] Nombre de viandes à choisir. Quand le profil publié exige une viande sur un plat
// que la carte locale décrit sans choix (burgers, Cayenne), l'assistant la DEMANDE — avant, l'envoi complétait en
// silence avec la première viande de la liste.
function lcRequiredMeats(item) {
  if (!item) return 0;
  const base = item.viande_count || item.viandes || 0;
  const o = window.LC && window.LC.currentItemOptions;
  const l = (o && o.limits) || null;
  if (!l || !l.meatSlots) return base; // fiche serveur pas encore lue : carte locale
  // [2026-11-15] La fiche du serveur fait foi : en production le Suprême n'a AUCUN emplacement viande (recette fixe)
  // alors que la carte locale lui en donnait 2 → étape Viandes vide, plat incommandable.
  const slots = l.meatSlots.length;
  if (!slots) return 0;
  const voulu = l.viandeCount > 0 ? l.viandeCount : (base > 0 ? base : (l.meatMin > 0 ? 1 : 0));
  return Math.min(voulu, slots);
}

function computeActiveSteps(item, selections) {
  if (!item) return [STEP.RECAP];
  const template = wizardTemplateFor(item);
  const steps = [];

  // Per-template canonical sequence, filtered by item flags
  switch (template) {
    case 'tacos':
    case 'sandwich':
      // [GOAL-SYNC 2026-07-08] Pain/Galette AVANT viandes (parité web wizard-v2.jsx:68-73)
      if (item.has_pain_choice && (lcOffered('variations', 'Pain') || lcOffered('variations', 'Galette'))) steps.push(STEP.PAIN);
      if (lcRequiredMeats(item) > 0) steps.push(STEP.VIANDES);
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_crudites && lcOffersCrudites()) steps.push(STEP.CRUDITES);
      if (item.has_supplements !== false && lcOffersSupplements(item)) steps.push(STEP.SUPPLEMENTS);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      break;
    case 'burger':
      if (item.has_pain_choice && (lcOffered('variations', 'Pain') || lcOffered('variations', 'Galette'))) steps.push(STEP.PAIN); // parité web (case partagé)
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_crudites && lcOffersCrudites()) steps.push(STEP.CRUDITES);
      if (item.has_supplements !== false && lcOffersSupplements(item)) steps.push(STEP.SUPPLEMENTS);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      break;
    case 'bol':
      // [GOAL-SYNC 2026-07-08] Template BOL (cat 6) — canon fixture Bol Frites/Bol Riz :
      // viande (1/1, MEATS) → sauce (1/1, pool BOL_SAUCES) → suppléments bols (0/5,
      // sb-gratine riz_only) → boisson optionnelle (0/1). PAS de formule menu.
      if (lcRequiredMeats(item) > 0) steps.push(STEP.VIANDES);
      if (item.has_sauce !== false) steps.push(STEP.SAUCE);
      steps.push(STEP.BOL_SUPPLEMENTS);
      steps.push(STEP.BOL_DRINK);
      break;
    case 'assiette':
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false && lcOffersSupplements(item)) steps.push(STEP.SUPPLEMENTS);
      break;
    case 'omelette':
      // Ojja + Omelettes + Menus Enfants (V3.8) — frites already in price → no menu, no frites_style
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_crudites && lcOffersCrudites()) steps.push(STEP.CRUDITES);
      if (item.has_supplements !== false && lcOffersSupplements(item)) steps.push(STEP.SUPPLEMENTS);
      break;
    case 'salade':
      // [Owner re-cadrage 2026-05-11] Salade simplifiée : sauce(opt) + suppléments(opt) + menu(opt) + cascade.
      // Kiosk a 6 steps (garnitures+sauce+menu+frites_style+supplements+recap) — c'est une bêtise pour
      // une salade dont la composition est déjà fixée par le nom (Salade Royale = Laitue+Tomate+Maïs+Poulet+Olives).
      // Logique humaine = "choisis sauce optionnelle + suppléments optionnels + veux-tu rajouter frites/boisson ?"
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false && lcOffersSupplements(item)) steps.push(STEP.SUPPLEMENTS);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      break;
    case 'snacking':
      // Wings + tenders (cat 8/313). Sauce générique 12 sauces (U2 owner: pas BBQ/Nashville).
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      if (item.has_supplements !== false && lcOffersSupplements(item)) steps.push(STEP.SUPPLEMENTS);
      break;
    case 'custom':
      // [MOBILE-REALIGNMENT 2026-05-16] Custom composer profile driven by
      // item.composer_profile.steps[] (DB-mirror shape). Bols + Frites use 'custom'.
      // Reads from API in future wireup, hardcoded today via mobile/data/menu.js helpers.
      if (item.has_bol_wizard) {
        if (item.has_sauce) steps.push(STEP.SAUCE);            // step_key=sauce
        steps.push(STEP.BOL_SUPPLEMENTS);                       // step_key=bol_supplements
        steps.push(STEP.BOL_DRINK);                             // step_key=bol_drink (addon role)
        break;
      }
      if (item.has_frites_style) {
        steps.push(STEP.FRITES_STYLE);                          // step_key=frites_style
        // [2026-11-14] Les frites seules exigent une sauce au serveur (min 1) : sans cette étape, le serveur
        // choisissait « Ketchup » à la place du client (relecture adverse A2-011).
        if (item.has_sauce) steps.push(STEP.SAUCE);
        break;
      }
      // Unknown custom profile — fall through to simple
      // FALLTHROUGH
    case 'simple':
    default:
      // Cas spéciaux : frites standalone, sauce sup, ou direct add
      if (item.has_frites_style) steps.push(STEP.FRITES_STYLE);
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false && (item.has_sauce || item.has_frites_style) && lcOffersSupplements(item)) {
        steps.push(STEP.SUPPLEMENTS);
      }
      break;
  }

  // Tout autre plat dont le profil serveur exige une viande : l'étape Viandes passe en tête (après le pain).
  if (!steps.includes(STEP.VIANDES) && lcRequiredMeats(item) > 0) {
    steps.splice(steps.includes(STEP.PAIN) ? steps.indexOf(STEP.PAIN) + 1 : 0, 0, STEP.VIANDES);
  }

  // Cascade formule menu (inserted right after MENU step)
  const menuChoice = selections && selections.menuChoice;
  if (steps.includes(STEP.MENU) && menuChoice && menuChoice !== 'none') {
    const menuIdx = steps.indexOf(STEP.MENU);
    const cascade = [];
    if (menuChoice === 'full' || menuChoice === 'boisson') cascade.push(STEP.DRINK);
    if (menuChoice === 'full' || menuChoice === 'frites') {
      // [2026-11-14] Plus de « style de frites » DANS la formule : le serveur ne le facture ni ne le transmet
      // (FrontendOrderService::instructionWebAssainie, même règle que le site) — le client voyait +1/+2 €
      // jamais encaissés et la cuisine ne recevait pas le cheddar (relecture adverse A2-003).
      cascade.push(STEP.FRITES_SAUCE);
    }
    steps.splice(menuIdx + 1, 0, ...cascade);
  }

  // Always end with recap (unless truly direct-add: no other steps)
  if (steps.length > 0) steps.push(STEP.RECAP);
  return steps;
}

// ============================================================================
// Per-step validation (gates the "Suivant" CTA)
// ============================================================================
function canAdvance(stepKey, selections, item) {
  switch (stepKey) {
    case STEP.PAIN:
      // [GOAL-SYNC 2026-07-08] défaut Pain pré-sélectionné à l'init → toujours valide en pratique
      return !!selections.painId;
    case STEP.VIANDES:
      return (selections.meatIds || []).length === lcRequiredMeats(item);
    case STEP.SAUCE:
      return (selections.sauceIds || []).length >= 1;
    case STEP.CRUDITES:
      return true; // always valid (toggle, default ON)
    case STEP.SUPPLEMENTS:
      return true; // optional
    case STEP.MENU:
      return !!selections.menuChoice; // requires explicit choice (none/full/frites/boisson)
    case STEP.DRINK:
      return !!selections.drinkId;
    case STEP.FRITES_STYLE:
      // null = "Nature" is a valid choice — distinguish from undefined (not yet picked)
      return selections.fritesStyleId !== undefined;
    case STEP.FRITES_SAUCE:
      return (selections.fritesSauceIds || []).length >= 1;
    case STEP.BOL_SUPPLEMENTS:
      return true; // optional (0-4 supplements bols)
    case STEP.BOL_DRINK:
      return true; // optional (can skip without picking)
    case STEP.RECAP:
      return true;
    default:
      return true;
  }
}

// ============================================================================
// Pricing wrapper (mobile/data/menu.js priceFor + cascade)
// ============================================================================
function computeTotal(item, selections) {
  const lcMenu = window.LC.menu;
  return lcMenu.priceFor(item, {
    sauceIds: selections.sauceIds,
    supplementIds: selections.supplementIds,
    // [GOAL-SYNC 2026-07-08] viandes supplémentaires +2,50 € chacune (priceFor canon)
    extraMeatIds: selections.extraMeatIds,
    bolSupplementIds: selections.bolSupplementIds,
    bolDrinkId: selections.bolDrinkId,
    formuleId: selections.menuChoice && selections.menuChoice !== 'none'
      ? (selections.menuChoice === 'full' ? 'f-menu' : selections.menuChoice === 'frites' ? 'f-frites' : 'f-boisson')
      : null,
    // Style de frites facturé UNIQUEMENT pour un produit Frites seul (SKU « … Cheddar fondu » au serveur).
    fritesStyleId: item && item.has_frites_style ? selections.fritesStyleId : null,
    fritesSauceIds: selections.fritesSauceIds,
    qty: selections.qty || 1,
  });
}

// ============================================================================
// Common shell — header + dots + sticky CTA (Claude Design rdw-* refactor cycle C 2026-05-11)
// Preserves all a11y (aria-live, role, tabIndex, aria-disabled, focus mgmt) +
// FSM (back/onClose handlers + stepIndex/stepTotal/title/headingRef).
// ============================================================================
function WizardHeader({ stepIndex, stepTotal, title, onBack, onClose, headingRef, scrolled = false, item, total }) {
  // [2026-11-15] Le plat « reçoit » l'option : un anneau part de sa vignette quand le prix change (jamais à l'ouverture).
  const firstTotal = useRef_w(total);
  const ring = typeof total === 'number' && total !== firstTotal.current;
  if (ring) firstTotal.current = null;
  return (
    <div className={'rdw-header' + (scrolled ? ' is-scrolled' : '')} style={{ position: 'sticky', top: 0, zIndex: 5 }}>
      {/* [UI/UX 2026-11-15] Une seule rangée : retour · le plat (photo, nom, étape, prix qui suit les choix) · fermer.
          Le plat reste sous les yeux à chaque étape ; le compteur d'étapes vit dans la ligne sous le nom. */}
      <div className="rdw-header-row" style={{ gap: 10 }}>
        <button
          className="rdw-back"
          onClick={onBack}
          aria-label={stepIndex === 0 ? 'Fermer le wizard' : 'Étape précédente'}
        >
          <I.Back size={20}/>
        </button>
        {item ? (
          <div className="lc-wiz-product" data-testid="wizard-product-strip" style={{ flex: 1, minWidth: 0, margin: 0 }}>
            <div className="lc-wiz-product__art" aria-hidden="true">{item.image && <img src={item.image} alt=""/>}{(ring || firstTotal.current === null) && <i key={total} className="lc-art-ring"/>}</div>
            <div className="lc-wiz-product__name"><span className="lc-wiz-product__title" title={item.name}>{item.name}</span><small className="rdw-stepcount" role="status" aria-live="polite" style={{ letterSpacing: 0, fontFamily: 'var(--font-body)' }}>Étape <b style={{ fontSize: 'inherit' }}>{stepIndex + 1}</b> sur {stepTotal}</small></div>
            {typeof total === 'number' && <span className="lc-wiz-product__price" role="status" aria-live="polite" aria-label={`Prix actuel ${total.toFixed(2).replace('.', ',')} euros`}><Bump value={total.toFixed(2)}>{total.toFixed(2).replace('.', ',')} €</Bump></span>}
          </div>
        ) : (
          <span className="rdw-stepcount" role="status" aria-live="polite">
            <b>{stepIndex + 1}</b> / {stepTotal}
          </span>
        )}
        <button
          className="rdw-back"
          onClick={onClose}
          aria-label="Fermer"
        >
          <I.Close size={18}/>
        </button>
      </div>
      <div className="rdw-title">
        <h1 ref={headingRef} tabIndex={-1} style={{ margin: 0, outline: 'none' }}>
          {title}
        </h1>
      </div>
      <div className="rdw-progress" role="progressbar" aria-valuenow={stepIndex + 1} aria-valuemin={1} aria-valuemax={stepTotal} aria-valuetext={`Étape ${stepIndex + 1} sur ${stepTotal}`}>
        {Array.from({ length: stepTotal }, (_, i) => (
          <span key={i} className={i < stepIndex ? 'done' : i === stepIndex ? 'current' : ''}/>
        ))}
      </div>
    </div>
  );
}

function WizardCTA({ label, total, disabled, hint, onClick }) {
  return (
    <div className="rdw-cta-wrap">
      {hint && (
        <div id="wizard-cta-hint" role="status" aria-live="polite" style={{ textAlign: 'center', fontSize: 'var(--fs-caption)', color: 'var(--gray-4)', fontWeight: 600, marginBottom: 8 }}>
          {hint}
        </div>
      )}
      <button
        className="rdw-cta"
        onClick={disabled ? undefined : onClick}
        aria-disabled={disabled ? 'true' : 'false'}
        aria-describedby={disabled && hint ? 'wizard-cta-hint' : undefined}
        disabled={disabled}
        style={{
          background: disabled ? 'var(--gray-2)' : 'var(--ink)',
          color: disabled ? 'var(--gray-4)' : '#fff',
          cursor: disabled ? 'not-allowed' : 'pointer',
        }}
      >
        <span className="rdw-cta-label">{label}</span>
        {total !== undefined && (
          <span key={total.toFixed(2)} className="rdw-cta-chip lc-bump" style={{ background: disabled ? 'transparent' : 'rgba(255,255,255,0.14)' }}>
            {total.toFixed(2).replace('.', ',')} €
          </span>
        )}
      </button>
    </div>
  );
}

// ============================================================================
// Reusable interactive primitives — A11y-correct (role + tabindex + keydown)
// Claude Design rdw-choice classes (cycle C 2026-05-11) — preserve aria + role.
// ============================================================================
function ChoiceCard({ on, onPick, ariaRole = 'radio', children, accent = 'orange', disabled = false, rupture = false }) {
  if (rupture) disabled = true;
  const handleKey = (e) => {
    if (disabled) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      pick();
    }
  };
  // Note: accent='green' for crudites step (Type 3 visual contract). The
  // rdw-choice.is-on uses --orange shadow by default ; accent override only
  // applies via inline overrides below (preserve cycle B contract).
  const isGreen = accent === 'green';
  // [UI/UX 2026-11-15] Un choix = une petite vibration (Android) + la carte respire UNE fois (classe posée au geste,
  // jamais au simple affichage : une étape qui s'ouvre avec des choix déjà faits reste calme).
  const [justPicked, setJustPicked] = useState_w(false);
  useEffect_w(() => { if (!justPicked) return undefined; const t = setTimeout(() => setJustPicked(false), 360); return () => clearTimeout(t); }, [justPicked]);
  const pick = () => { if (window.lcHaptic) window.lcHaptic(8); setJustPicked(true); onPick(); };
  return (
    <div
      role={ariaRole}
      aria-checked={on ? 'true' : 'false'}
      aria-disabled={disabled ? 'true' : undefined}
      tabIndex={disabled ? -1 : 0}
      onClick={disabled ? undefined : pick}
      onKeyDown={handleKey}
      className={'rdw-choice' + (on ? ' is-on' : '') + (justPicked && on ? ' lc-just-picked' : '') + (rupture ? ' is-rupture' : '')}
      style={{
        opacity: disabled && !rupture ? 0.4 : 1,
        cursor: disabled ? 'not-allowed' : 'pointer',
        outline: 'none',
        // Override is-on shadow for green accent (crudites)
        ...(on && isGreen ? { boxShadow: '0 0 0 2px var(--green), 0 8px 20px -8px rgba(31,166,83,0.35)', background: '#FFFEFB' } : {}),
      }}
    >
      {children}
    </div>
  );
}

// ============================================================================
// Helpers pools — [GOAL-SYNC 2026-07-08]
// ============================================================================
// Recherche du nom d'une sauce dans TOUS les pools (SAUCES génériques + BOL_SAUCES
// 'bs-*') — le recap/panier des bols doit résoudre les ids du pool bol.
function sauceNameFor(lcMenu, id) {
  const pools = [lcMenu.sauces || [], lcMenu.bolSauces || []];
  for (let p = 0; p < pools.length; p++) {
    const found = pools[p].find(s => s.id === id);
    if (found) return found.name;
  }
  return null;
}

// Prix « Viande supplémentaire » — lit lcMenu.extraMeatPrice (canon 2,50 €).
function extraMeatPriceOf(lcMenu) {
  return typeof lcMenu.extraMeatPrice === 'number' ? lcMenu.extraMeatPrice : 2.50;
}

// ============================================================================
// STEP 0 — Pain ou galette ([GOAL-SYNC 2026-07-08] has_pain_choice, pool PAINS,
// défaut Pain — parité web wizard-v2.jsx:68-73, gratuit)
// ============================================================================
// [2026-11-14] Filtre « proposé par le serveur pour CE produit » (chargé à l'ouverture de l'assistant).
// Tant que la réponse n'est pas arrivée (ou hors ligne), tout reste affiché ; la commande échoue alors
// clairement si une option n'existe pas (api/client.js optionAbsente).
// Étape crudités seulement si le profil serveur du produit en propose (Tacos L : aucune — R2).
function lcOffersCrudites() {
  return ['Salade', 'Tomate', 'Oignon'].some(n => lcOffered('extras', n));
}
// Étape suppléments seulement si le profil propose au moins un supplément ou la viande en plus.
function lcOffersSupplements(item) {
  const lcMenu = window.LC.menu;
  return (lcMenu.supplements || []).some(sp => lcOffered('extras', sp.name))
    || (lcMenu.supplementsBols || []).some(sp => lcOffered('extras', sp.name))
    || (!!item.has_extra_meat && lcOffered('extras', 'Viande supplémentaire'));
}
// Sauce frites de formule : seulement une sauce que le serveur vend (« Sauce … » au catalogue) — R3-003.
function lcSauceVendue(name) {
  const set = window.LC && window.LC.catalog && window.LC.catalog.sauceNames;
  const api = window.LC && window.LC.mobileApi;
  if (!set || !api || !api._norm) return true;
  return !!set[api._norm(name)];
}
function lcOffered(kind, name) {
  const o = window.LC && window.LC.currentItemOptions;
  const api = window.LC && window.LC.mobileApi;
  if (!o || !api || !api._norm) return true;
  const table = o[kind] || {};
  // Profil publié chargé : une rubrique vide veut dire « rien de proposé » (Tacos : ni supplément ni
  // crudités — R3-001) ; sans profil, on ne restreint pas.
  if (!Object.keys(table).length) return !o.profile;
  return !!table[api._norm(name)];
}

// [2026-11-15] Vendu avec ce plat mais en rupture aujourd'hui (voir api/client.js `rupture`).
function lcRupture(kind, name) {
  const o = window.LC && window.LC.currentItemOptions;
  const api = window.LC && window.LC.mobileApi;
  if (!o || !o.rupture || !api || !api._norm) return false;
  return !!(o.rupture[kind] || {})[api._norm(name)];
}

function ScreenStepPain({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const pains = lcMenu.pains || [];
  const painId = selections.painId;
  const pick = (id) => setSelections(s => ({ ...s, painId: id }));
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        Gratuit · choisis ta base
      </p>
      <div role="radiogroup" aria-label="Pain ou galette" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 10 }}>
        {pains.map(p => {
          const on = painId === p.id;
          return (
            <ChoiceCard key={p.id} on={on} onPick={() => pick(p.id)} ariaRole="radio">
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, width: '100%', padding: '4px 0' }}>
                {p.image
                  ? <img src={p.image} alt="" aria-hidden="true" style={{ width: 44, height: 44, objectFit: 'cover', borderRadius: 8, flexShrink: 0 }} onError={(e) => { e.target.style.display = 'none'; }}/>
                  : (window.NeutralAsset ? <window.NeutralAsset label={`${p.name} — photo indisponible`} style={{ width: 44, height: 44, borderRadius: 8, flexShrink: 0 }} /> : null)}
                <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--ink)', textAlign: 'center' }}>{p.name}</span>
                <span aria-hidden="true" style={{ width: 18, height: 18, borderRadius: 999, border: on ? '5px solid var(--orange)' : '2px solid var(--gray-2)', background: '#fff' }}/>
              </div>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 1 — Viandes
// ============================================================================
// [2026-11-15] Quantités — règles lues dans le profil publié du serveur (api/client.js profileChoiceIds.limits).
// Le serveur compte TOUT le groupe « suppléments » (suppléments + viande en plus, avec leur quantité) et refuse
// une répétition tant que l'étape n'a pas `allow_repeat` : l'app propose exactement ce qu'il accepte.
const LC_MAX_EXTRA_MEATS = 4; // plafond voulu par le propriétaire (2026-11-15), jamais au-dessus du serveur
function lcLimits() { const o = window.LC && window.LC.currentItemOptions; return (o && o.limits) || null; }
function lcSupMax() { const l = lcLimits(); return l && l.extrasMax > 0 ? l.extrasMax : 5; }
function lcSupRepeat() { const l = lcLimits(); return !!(l && l.extrasRepeat); }
function lcCount(arr, id) { return (arr || []).filter(x => x === id).length; }
function lcSupTotal(s) { return (s.supplementIds || []).length + (s.bolSupplementIds || []).length + (s.extraMeatIds || []).length; }
function lcExtraMeatCap(item) {
  if (!item || !item.has_extra_meat || !lcOffered('extras', 'Viande supplémentaire')) return 0;
  return lcSupRepeat() ? Math.min(LC_MAX_EXTRA_MEATS, lcSupMax()) : 1;
}
// Combien de fois une viande peut figurer parmi les viandes INCLUSES : une fois par emplacement qui la propose.
function lcMeatSlotCount(name, required) {
  const l = lcLimits();
  const api = window.LC && window.LC.mobileApi;
  if (!l || !l.meatSlots || !l.meatSlots.length || !api || !api._norm) return required;
  const n = api._norm(name);
  return l.meatSlots.slice(0, required).filter(slot => slot[n]).length;
}
// « Cheddar, Cheddar, Œuf » → « Cheddar ×2 », « Œuf » (ordre du premier choix conservé).
function lcGroupLabels(labels) {
  const out = [];
  (labels || []).forEach(l => { const f = out.find(x => x.l === l); if (f) f.n += 1; else out.push({ l, n: 1 }); });
  return out.map(x => x.n > 1 ? x.l + ' ×' + x.n : x.l);
}
// Refus expliqué : le compteur tressaute et dit pourquoi (jamais un toucher sans réponse).
function useNudge() {
  const [n, setN] = useState_w(0);
  const [msg, setMsg] = useState_w(null);
  useEffect_w(() => { if (!n) return undefined; const t = setTimeout(() => setMsg(null), 2600); return () => clearTimeout(t); }, [n]);
  return { n, msg, nudge: (m) => { if (window.lcHaptic) window.lcHaptic(18); setMsg(m); setN(x => x + 1); } };
}
// Jauge « 2 / 5 » : un cran par choix possible.
function CapMeter({ used, max, label, nudge }) {
  return (
    <div className="lc-cap" data-testid="cap-meter">
      <div key={nudge.n} className={'lc-cap__row' + (nudge.n ? ' lc-shake' : '')}>
        <span className="lc-cap__label">{label}</span>
        <span className="lc-cap__dots" aria-hidden="true">
          {Array.from({ length: max }, (_, k) => <i key={k} className={k < used ? 'on' : ''}/>)}
        </span>
        <span className={'lc-cap__count' + (used >= max ? ' is-full' : '')}><Bump value={used}>{used}</Bump> / {max}</span>
      </div>
      <div role="status" aria-live="polite" className={'lc-cap__msg' + (nudge.msg ? ' is-on' : '')}>{nudge.msg || ''}</div>
    </div>
  );
}
// Ligne d'option à quantité : un toucher ajoute ; si le serveur autorise la répétition, un compteur − n + remplace
// la coche. Sans répétition, la ligne reste une case à cocher (un second toucher retire).
function QtyRow({ image, name, priceLabel, count, repeat, canAdd, onAdd, onRemove, onBlocked, testid, badge, rupture = false }) {
  const on = count > 0;
  if (rupture && !on) canAdd = false;
  const tap = () => {
    if (!on || repeat) { if (canAdd) { if (window.lcHaptic) window.lcHaptic(8); onAdd(); } else onBlocked(); return; }
    if (window.lcHaptic) window.lcHaptic(8); onRemove();
  };
  const handleKey = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tap(); } };
  const dim = !on && !canAdd;
  return (
    <div className={'lc-toggle-row lc-qrow' + (on ? ' is-on' : '') + (dim ? ' is-dim' : '') + (rupture && !on ? ' is-rupture' : '')} data-testid={testid}>
      <div role="checkbox" aria-checked={on ? 'true' : 'false'} aria-disabled={dim ? 'true' : undefined} aria-label={`${name}${priceLabel ? ' ' + priceLabel : ''}${count > 1 ? ', ' + count + ' fois' : ''}`}
        tabIndex={0} onClick={tap} onKeyDown={handleKey} className="lc-qrow__main" style={{ outline: 'none' }}>
        {image
          ? <img src={image} alt="" aria-hidden="true" className="lc-qrow__img" onError={(e) => { e.currentTarget.style.visibility = 'hidden'; }}/>
          : null}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="lc-qrow__name">{name}{badge}</div>
          {rupture && !on
            ? <div className="lc-rupture-tag">Rupture aujourd'hui</div>
            : priceLabel && <div className="lc-qrow__price">{priceLabel}{count > 1 ? <span className="lc-qrow__mult"> × {count}</span> : null}</div>}
        </div>
      </div>
      {repeat && on ? (
        <div className="lc-qty" role="group" aria-label={`Quantité ${name}`}>
          <button type="button" onClick={() => { if (window.lcHaptic) window.lcHaptic(8); onRemove(); }} aria-label={`Retirer un ${name}`}><I.Minus size={14}/></button>
          <span aria-live="polite"><Bump value={count}>{count}</Bump></span>
          <button type="button" className={canAdd ? 'is-plus' : 'is-plus is-off'} onClick={() => { if (canAdd) { if (window.lcHaptic) window.lcHaptic(8); onAdd(); } else onBlocked(); }} aria-label={`Ajouter un ${name}`}><I.Plus size={14}/></button>
        </div>
      ) : on ? (
        <div aria-hidden="true" className="lc-checkbox lc-checkbox--on lc-check-draw" onClick={tap}><I.Check size={12} stroke="#fff" sw={3}/></div>
      ) : (
        <div aria-hidden="true" className="lc-qrow__add" onClick={tap}><I.Plus size={14}/></div>
      )}
    </div>
  );
}

// [2026-11-15] Étape viandes refaite : chaque toucher AJOUTE une viande (la même deux fois si le plat a deux
// emplacements qui la proposent). Les emplacements inclus se remplissent sous les yeux du client ; une fois pleins,
// chaque carte propose « + en plus » (extra serveur « Viande supplémentaire », payant) dans la limite du profil.
function ScreenStepViandes({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const meatIds = selections.meatIds || [];
  const extraIds = selections.extraMeatIds || [];
  const required = lcRequiredMeats(item);
  const extraCap = lcExtraMeatCap(item);
  const emPrice = extraMeatPriceOf(lcMenu).toFixed(2).replace('.', ',');
  const full = meatIds.length >= required;
  const extraRoom = Math.min(extraCap - extraIds.length, lcSupMax() - lcSupTotal(selections));
  const nudge = useNudge();
  const [lastId, setLastId] = useState_w(null);
  const nameOf = (id) => (lcMenu.meats.find(m => m.id === id) || {}).name || '';
  const mark = (id) => { if (window.lcHaptic) window.lcHaptic(8); setLastId(id + ':' + Date.now()); };

  const tapCard = (m) => {
    const mine = lcCount(meatIds, m.id);
    if (!mine && lcRupture('variations', m.name)) { nudge.nudge(`${m.name} est en rupture aujourd'hui — choisis une autre viande`); return; }
    if (!full) {
      if (mine >= lcMeatSlotCount(m.name, required)) { nudge.nudge(mine === 0 ? `${m.name} n'est plus disponible pour ce plat aujourd'hui` : `${m.name} : ${mine > 1 ? mine + ' fois au plus' : 'une seule fois'} ici — choisis une autre viande`); return; }
      mark(m.id);
      setSelections(s => ({ ...s, meatIds: [...(s.meatIds || []), m.id] }));
      return;
    }
    // Une seule viande incluse : toucher une autre viande la REMPLACE (jamais d'ajout payant par accident).
    if (required === 1 && mine === 0 && lcCount(extraIds, m.id) === 0) {
      mark(m.id);
      setSelections(s => ({ ...s, meatIds: [m.id] }));
      return;
    }
    nudge.nudge(extraCap > 0
      ? (extraRoom > 0 ? `C'est complet — touche « + en plus » pour en ajouter (+${emPrice} €)` : 'Maximum atteint — retire une viande avec −')
      : 'C\'est complet — retire une viande avec − pour changer');
  };
  const addExtra = (m) => {
    if (extraRoom <= 0) { nudge.nudge(extraIds.length >= extraCap ? `Maximum : ${extraCap} viande${extraCap > 1 ? 's' : ''} en plus` : 'Maximum de suppléments atteint pour ce plat'); return; }
    mark(m.id);
    setSelections(s => ({ ...s, extraMeatIds: [...(s.extraMeatIds || []), m.id] }));
  };
  // Retirer : la viande EN PLUS (payante) part d'abord ; si une incluse part, une viande en plus prend sa place
  // (le client ne paie jamais un supplément tant qu'un emplacement inclus est libre).
  const removeOne = (id, from) => {
    if (window.lcHaptic) window.lcHaptic(8);
    setLastId(null);
    setSelections(s => {
      const inc = (s.meatIds || []).slice();
      const ext = (s.extraMeatIds || []).slice();
      const takeExtra = from === 'extra' || (from !== 'included' && ext.includes(id));
      if (takeExtra && ext.includes(id)) { ext.splice(ext.lastIndexOf(id), 1); return { ...s, extraMeatIds: ext }; }
      if (!inc.includes(id)) return s;
      inc.splice(inc.lastIndexOf(id), 1);
      const promu = ext.findIndex(x => lcCount(inc, x) < lcMeatSlotCount(nameOf(x), required));
      if (promu >= 0) inc.push(ext.splice(promu, 1)[0]);
      return { ...s, meatIds: inc, extraMeatIds: ext };
    });
  };

  return (
    <div style={{ padding: '16px 20px 120px' }}>
      {/* Plateau : les emplacements inclus + les viandes en plus, dans l'ordre choisi. */}
      <div className="lc-tray" data-testid="meat-tray">
        <div key={nudge.n} className={'lc-tray__head' + (nudge.n ? ' lc-shake' : '')} role="status" aria-live="polite">
          <span>{required > 1 ? <span><strong>{required} viandes</strong> comprises</span> : <span><strong>1 viande</strong> comprise</span>}</span>
          <span className={'lc-tray__count' + (full ? ' is-full' : '')}><Bump value={meatIds.length}>{meatIds.length}</Bump>/{required}</span>
        </div>
        <div className="lc-tray__slots">
          {Array.from({ length: required }, (_, k) => {
            const id = meatIds[k];
            const m = id ? lcMenu.meats.find(x => x.id === id) : null;
            return m ? (
              <button type="button" key={k + ':' + id} className="lc-slot is-filled" onClick={() => removeOne(id, 'included')} aria-label={`Retirer ${m.name}`}>
                {m.image && <img src={m.image} alt="" aria-hidden="true"/>}
                <span className="lc-slot__name">{m.name}</span>
                <span className="lc-slot__tag">Comprise</span>
                <span className="lc-slot__x" aria-hidden="true"><I.Close size={11}/></span>
              </button>
            ) : (
              <div key={k + ':vide'} className={'lc-slot' + (k === meatIds.length ? ' is-next' : '')} aria-hidden="true">
                <span className="lc-slot__num">{k + 1}</span>
                <span className="lc-slot__name">{k === meatIds.length ? 'Choisis ci-dessous' : 'Viande ' + (k + 1)}</span>
              </div>
            );
          })}
          {extraIds.map((id, k) => {
            const m = lcMenu.meats.find(x => x.id === id);
            return m ? (
              <button type="button" key={'x' + k + ':' + id} className="lc-slot is-filled is-extra" data-testid="meat-extra-chip" onClick={() => removeOne(id, 'extra')} aria-label={`Retirer ${m.name} en plus`}>
                {m.image && <img src={m.image} alt="" aria-hidden="true"/>}
                <span className="lc-slot__name">{m.name}</span>
                <span className="lc-slot__tag">+{emPrice} €</span>
                <span className="lc-slot__x" aria-hidden="true"><I.Close size={11}/></span>
              </button>
            ) : null;
          })}
        </div>
        <div role="status" aria-live="polite" className={'lc-cap__msg' + (nudge.msg ? ' is-on' : '')}>{nudge.msg || ''}</div>
        {extraCap > 0 && lcMenu.meats.some(m => lcOffered('variations', m.name)) && (
          <p className="lc-tray__foot">
            {full
              ? (extraRoom > 0
                ? <span>Encore faim ? <strong>Viande en plus +{emPrice} €</strong>{extraCap > 1 ? ` · jusqu'à ${extraCap}` : ''}</span>
                : <span>{extraIds.length >= extraCap ? `Viande en plus : maximum atteint (${extraCap})` : 'Maximum de suppléments atteint pour ce plat'}</span>)
              : <span>Ensuite, tu pourras ajouter de la viande en plus (+{emPrice} €)</span>}
          </p>
        )}
      </div>

      <div role="radiogroup" aria-label={`Choisis ${required} viandes`} style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8, marginTop: 14, alignItems: 'start' }}>
        {lcMenu.meats.filter(m => lcOffered('variations', m.name) || lcRupture('variations', m.name)).map(m => {
          const rupture = !lcOffered('variations', m.name);
          const inc = lcCount(meatIds, m.id);
          const ext = lcCount(extraIds, m.id);
          const count = inc + ext;
          const on = count > 0;
          const fresh = lastId && lastId.indexOf(m.id + ':') === 0;
          const handleKey = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tapCard(m); } };
          return (
            <div key={m.id} className={'lc-meat' + (on ? ' is-on' : '') + (rupture && !on ? ' is-rupture' : '')} data-testid={`meat-card-${m.id}`}>
              <div role="checkbox" aria-checked={on ? 'true' : 'false'} aria-disabled={rupture && !on ? 'true' : undefined} tabIndex={0} onClick={() => tapCard(m)} onKeyDown={handleKey}
                aria-label={`${m.name}${count > 1 ? ', ' + count + ' fois' : ''}`}
                className={'rdw-choice lc-meat__card' + (on ? ' is-on' : '') + (fresh && on ? ' lc-just-picked' : '')} style={{ outline: 'none' }}>
                <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 8, minWidth: 0, flex: 1 }}>
                  {m.image && <img
                    data-testid={`meat-choice-image-${m.id}`}
                    className="lc-wizard-choice-image lc-wizard-choice-image--meat"
                    src={m.image}
                    alt=""
                    aria-hidden="true"
                    onError={(e) => { e.currentTarget.dataset.imageError = 'true'; }}
                  />}
                  <span style={{ minWidth: 0, overflowWrap: 'anywhere' }}>{m.name}{rupture && !on && <span className="lc-rupture-tag" style={{ display: 'block' }}>Rupture</span>}</span>
                </span>
                {on
                  ? <span key={count} aria-hidden="true" className="lc-meat__count lc-bump">{count > 1 ? '×' + count : <I.Check size={14} stroke="#fff" sw={3}/>}</span>
                  : <span aria-hidden="true" className="lc-meat__empty"/>}
              </div>
              {(on || (full && extraRoom > 0 && !rupture)) && (
                <div className="lc-meat__actions">
                  {on
                    ? <button type="button" className="lc-meat__minus" onClick={() => removeOne(m.id)} aria-label={`Retirer un ${m.name}`}><I.Minus size={14}/></button>
                    : <span/>}
                  {full && extraRoom > 0 && !rupture && (
                    <button type="button" className="lc-meat__plus" data-testid={`meat-extra-add-${m.id}`} onClick={() => addExtra(m)} aria-label={`Ajouter ${m.name} en plus, ${emPrice} euros`}>
                      <I.Plus size={12}/> en plus
                    </button>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 2 — Sauce
// [GOAL-SYNC 2026-07-08] Canon borne (fixture min_select=1/max_select=1) :
//   - templates tacos/sandwich/burger/bol → 1 sauce max, sélection radio-like,
//     plus AUCUNE tarification +0,50 € multi-sauce (supprimée du canon).
//   - bols → pool BOL_SAUCES (2 sauces) au lieu des 12 génériques.
//   - cascade frites (sauceField='fritesSauceIds') → multi conservé, gratuit
//     (instruction cuisine, cf. CONTRACTS.md §5).
// ============================================================================
function ScreenStepSauce({ item, selections, setSelections, headingRef, sauceField = 'sauceIds' }) {
  const lcMenu = window.LC.menu;
  const sauceIds = selections[sauceField] || [];
  const SANS_SAUCE = 's-sans';
  const isMainSauce = sauceField === 'sauceIds';
  const template = wizardTemplateFor(item);
  // [2026-11-14] Sauce frites de formule : une seule, offerte — le serveur ne garde que la 1re non payée
  // (instructionWebAssainie) ; « incluses » en sélection multiple promettait des sauces jamais servies.
  const isFritesSauce = sauceField === 'fritesSauceIds';
  // [2026-11-14] Nombre de sauces = maximum du PROFIL publié au serveur (Sandwich Classique / Cayenne : 2 sauces
  // incluses, max_select 2) ; 1 par défaut tant que le profil n'est pas chargé.
  // [2026-11-14 R4-001] Une seule sauce dans le champ sauce : le serveur la limite à 1 (attribut max 1) et
  // facture une 2ᵉ sauce comme « Sauce supplémentaire » — l'envoyer ici faisait refuser toute la commande.
  const profilSauceMax = 1;
  // [2026-11-14 R5-001] Sauce principale : TOUJOURS une seule (menus enfant compris — attribut serveur max 1).
  const maxOne = isFritesSauce || (isMainSauce && profilSauceMax === 1);
  const maxMulti = isMainSauce && profilSauceMax > 1 ? profilSauceMax : null;
  // [2026-11-14] Bol : avec le profil serveur chargé, la liste complète du serveur (14 sauces) — le pool local
  // « bolSauces » (2 noms) faisait disparaître la Spicy et « Sans sauce » (régression R2-001).
  const pool = ((isMainSauce && template === 'bol' && !window.LC.currentItemOptions && (lcMenu.bolSauces || []).length > 0)
    ? lcMenu.bolSauces
    : lcMenu.sauces).filter(x => /^sans/i.test(x.name) || (isFritesSauce ? lcSauceVendue(x.name) : (lcOffered('variations', x.name) || lcRupture('variations', x.name))));
  const enRupture = (x) => !isFritesSauce && !/^sans/i.test(x.name) && !lcOffered('variations', x.name);
  const toggle = (id) => {
    setSelections(s => {
      const arr = s[sauceField] || [];
      // Radio-like : 1 sauce max (canon min1/max1) — re-tap garde la sélection
      if (maxOne) return { ...s, [sauceField]: [id] };
      // "Sans Sauce" exclusivity (kiosk: KioskStepSauceComponent.vue:65-78)
      if (id === SANS_SAUCE) return { ...s, [sauceField]: [SANS_SAUCE] };
      if (maxMulti && !arr.includes(id) && arr.filter(x => x !== SANS_SAUCE).length >= maxMulti) return s; // plafond du profil
      const nextArr = arr.includes(id) ? arr.filter(x => x !== id) : [...arr.filter(x => x !== SANS_SAUCE), id];
      return { ...s, [sauceField]: nextArr };
    });
  };
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        {isFritesSauce ? <span><strong>1 sauce frites offerte</strong> · choisis-la</span> : maxOne ? <span><strong>1 sauce incluse</strong> · choisis ta sauce</span> : maxMulti ? <span><strong>{maxMulti} sauces incluses</strong> · choisis-en jusqu'à {maxMulti}</span> : 'Sauces au choix'}
      </p>
      <div role={maxOne ? 'radiogroup' : 'group'} aria-label="Sauces" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 6 }}>
        {pool.map(s => {
          const on = sauceIds.includes(s.id);
          return (
            <ChoiceCard key={s.id} on={on} onPick={() => toggle(s.id)} ariaRole={maxOne ? 'radio' : 'checkbox'} rupture={enRupture(s) && !on}>
              <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 6 }}>
                {/* Phase 6.A real-asset color swatch (kiosk generated sauce_*.svg) */}
                {s.image
                  ? <img src={s.image} alt="" aria-hidden="true" style={{ width: 18, height: 18, objectFit: 'cover', borderRadius: 999, flexShrink: 0, border: '1px solid var(--gray-2)' }} onError={(e) => { e.target.style.display = 'none'; }}/>
                  : (window.NeutralAsset ? <window.NeutralAsset label={`${s.name} — photo indisponible`} style={{ width: 18, height: 18, borderRadius: 999, flexShrink: 0 }} /> : null)}
                <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.name}{enRupture(s) && <span className="lc-rupture-tag" style={{ display: 'block' }}>Rupture</span>}</span>
              </span>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 3 — Crudités (toggle, default ON)
// ============================================================================
function ScreenStepCrudites({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const cruditeIds = selections.cruditeIds || lcMenu.defaultCruditeIds();
  const toggle = (id) => {
    setSelections(s => {
      const arr = s.cruditeIds || lcMenu.defaultCruditeIds();
      return { ...s, cruditeIds: arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id] };
    });
  };
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        Tu peux retirer ce que tu ne veux pas
      </p>
      <div role="group" aria-label="Crudités" style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
        {lcMenu.crudites.map(c => {
          const on = cruditeIds.includes(c.id);
          return (
            <ChoiceCard key={c.id} on={on} onPick={() => toggle(c.id)} ariaRole="checkbox" accent="green">
              { /* [test-e2e fix B-005 round-2 2026-05-11] longhand to avoid React warning */ }
              <div style={{ width: '100%', textAlign: 'center', textDecorationLine: on ? 'none' : 'line-through', textDecorationColor: 'var(--gray-3)' }}>
                {/* Phase 6.A real-asset thumb (kiosk crudite_*.png) */}
                {c.image && (
                  <img src={c.image} alt="" aria-hidden="true" style={{ width: 44, height: 44, objectFit: 'cover', borderRadius: 8, margin: '0 auto 6px', opacity: on ? 1 : 0.35, display: 'block' }}/>
                )}
                <div style={{ fontSize: 13, fontWeight: 700, color: on ? 'var(--green)' : 'var(--gray-3)' }}>
                  <span aria-hidden="true">{on ? '✓' : '✕'}</span> {c.name}
                </div>
              </div>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// Section partagée — Viande supplémentaire (+2,50 € · multi 0..2)
// [GOAL-SYNC 2026-07-08] item.has_extra_meat → rendue dans l'étape suppléments
// (et suppléments bols) — parité web wizard-v2.jsx viande_extra (max 2).
// ============================================================================
function ExtraMeatSection({ item, selections, setSelections, nudge }) {
  const lcMenu = window.LC.menu;
  const cap = lcExtraMeatCap(item);
  // Avec une étape Viandes, la viande en plus se choisit là-bas (un seul endroit pour la même chose).
  if (!cap || computeActiveSteps(item, selections).includes(STEP.VIANDES)) return null;
  const emPrice = extraMeatPriceOf(lcMenu);
  const extraMeatIds = selections.extraMeatIds || [];
  const repeat = lcSupRepeat();
  const room = Math.min(cap - extraMeatIds.length, lcSupMax() - lcSupTotal(selections));
  const priceLabel = emPrice.toFixed(2).replace('.', ',');
  const add = (id) => setSelections(s => ({ ...s, extraMeatIds: [...(s.extraMeatIds || []), id] }));
  const remove = (id) => setSelections(s => { const a = (s.extraMeatIds || []).slice(); const k = a.lastIndexOf(id); if (k >= 0) a.splice(k, 1); return { ...s, extraMeatIds: a }; });
  return (
    <div style={{ marginTop: 18 }} data-testid="extra-meat-section">
      <p style={{ margin: '0 0 10px', fontSize: 14, color: 'var(--gray-4)' }}>
        Viande en plus <strong>+{priceLabel} €</strong> · {cap > 1 ? `jusqu'à ${cap}` : '1 au choix'}
      </p>
      {nudge && <div role="status" aria-live="polite" className={'lc-cap__msg' + (nudge.msg ? ' is-on' : '')} style={{ marginBottom: nudge.msg ? 8 : 0 }}>{nudge.msg || ''}</div>}
      <div role="group" aria-label={`Viande supplémentaire +${priceLabel} €`} style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
        {/* Profil sans étape viande (aucune viande connue pour ce plat) : toutes les viandes, comme avant. */}
        {(lcMenu.meats.some(m => lcLimits() && lcLimits().meatSlots.length && (lcOffered('variations', m.name) || lcRupture('variations', m.name)))
          ? lcMenu.meats.filter(m => lcOffered('variations', m.name) || lcRupture('variations', m.name))
          : lcMenu.meats).map(m => (
          <QtyRow key={m.id} rupture={!!(lcLimits() && lcLimits().meatSlots.length) && !lcOffered('variations', m.name)} testid={`extra-meat-${m.id}`} image={m.image} name={m.name} priceLabel={`+ ${priceLabel} €`}
            count={lcCount(extraMeatIds, m.id)} repeat={repeat && cap > 1} canAdd={room > 0}
            onAdd={() => add(m.id)} onRemove={() => remove(m.id)}
            onBlocked={() => nudge && nudge.nudge(lcRupture('variations', m.name) && !lcOffered('variations', m.name) ? `${m.name} est en rupture aujourd'hui` : extraMeatIds.length >= cap ? `Maximum : ${cap} viande${cap > 1 ? 's' : ''} en plus` : 'Maximum de suppléments atteint pour ce plat')}/>
        ))}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 4 — Suppléments (optionnel)
// [GOAL-SYNC 2026-07-08] Filtres canon : galette_only visible SEULEMENT cat 2 ;
// galette_excluded masqué en cat 2. + section Viande supplémentaire (has_extra_meat).
// ============================================================================
function ScreenStepSupplements({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const supplementIds = selections.supplementIds || [];
  const isGalette = item.category_id === 2;
  const pool = lcMenu.supplements.filter(s => {
    if (!lcOffered('extras', s.name) && !lcRupture('extras', s.name)) return false;
    if (s.galette_only && !isGalette) return false;
    if (s.galette_excluded && isGalette) return false;
    return true;
  });
  // [2026-11-14 R4-002] plafond du profil serveur (la viande en plus compte dans le même groupe).
  // [2026-11-15] plafond et répétition LUS dans le profil ; chaque refus est expliqué (jauge + message).
  const max = lcSupMax();
  const repeat = lcSupRepeat();
  const used = lcSupTotal(selections);
  const nudge = useNudge();
  const add = (id) => setSelections(s => (lcSupTotal(s) >= max ? s : { ...s, supplementIds: [...(s.supplementIds || []), id] }));
  const remove = (id) => setSelections(s => { const a = (s.supplementIds || []).slice(); const k = a.lastIndexOf(id); if (k >= 0) a.splice(k, 1); return { ...s, supplementIds: a }; });
  return (
    <div style={{ padding: '16px 20px 120px' }}>
      <CapMeter used={used} max={max} label={repeat ? 'Optionnel · touche deux fois pour doubler' : 'Optionnel · ajoute ce que tu veux'} nudge={nudge}/>
      {(selections.extraMeatIds || []).length > 0 && computeActiveSteps(item, selections).includes(STEP.VIANDES) && (
        <p style={{ margin: '-4px 0 12px', fontSize: 12, color: 'var(--gray-4)' }}>dont {(selections.extraMeatIds || []).length} viande{(selections.extraMeatIds || []).length > 1 ? 's' : ''} en plus (étape Viandes)</p>
      )}
      <div role="group" aria-label="Suppléments" style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
        {pool.map(s => (
          <QtyRow key={s.id} rupture={!lcOffered('extras', s.name)} testid={`supp-${s.id}`} image={s.image} name={s.name} priceLabel={`+ ${s.price.toFixed(2).replace('.', ',')} €`}
            count={lcCount(supplementIds, s.id)} repeat={repeat} canAdd={used < max}
            onAdd={() => add(s.id)} onRemove={() => remove(s.id)}
            onBlocked={() => nudge.nudge(!lcOffered('extras', s.name) ? `${s.name} est en rupture aujourd'hui` : `Maximum ${max} suppléments par plat — retires-en un pour changer`)}/>
        ))}
      </div>
      {/* Viande en plus : ici seulement pour les plats sans étape Viandes (burgers, galettes…). */}
      <ExtraMeatSection item={item} selections={selections} setSelections={setSelections} nudge={nudge}/>
    </div>
  );
}

// ============================================================================
// STEP 5 — Menu (radio: full/frites/boisson/none)
// ============================================================================
const FORMULA_CHOICE_IMAGES = {
  full: 'assets/menu/menu_complet.png',
  frites: 'assets/menu/frites.png',
  boisson: 'assets/menu/boisson.png',
};

function ScreenStepMenu({ item, selections, setSelections, headingRef }) {
  const choice = selections.menuChoice;
  const pick = (val) => {
    setSelections(s => {
      const next = { ...s, menuChoice: val };
      // Reset cascade selections on choice change to invalid combo
      if (val === 'none') {
        next.drinkId = undefined;
        next.fritesStyleId = undefined;
        next.fritesSauceIds = [];
      } else if (val === 'frites') {
        next.drinkId = undefined;
      } else if (val === 'boisson') {
        next.fritesStyleId = undefined;
        next.fritesSauceIds = [];
      }
      return next;
    });
  };
  // [GOAL-SYNC 2026-07-08] FIX P1 : prix lus depuis FORMULES (une seule source —
  // l'étape affichait +3,00 € alors que priceFor facturait f-menu 2,50 €).
  const formules = (window.LC.menu && window.LC.menu.formules) || [];
  const formulePrice = (id, fallback) => {
    const f = formules.find(x => x.id === id);
    return f ? f.price : fallback;
  };
  const options = [
    { id: 'full',    name: 'Menu complet',  desc: 'Frites + Boisson', price: formulePrice('f-menu', 2.50), image: FORMULA_CHOICE_IMAGES.full },
    { id: 'frites',  name: 'Ajouter Frites', desc: 'Frites uniquement', price: formulePrice('f-frites', 1.90), image: FORMULA_CHOICE_IMAGES.frites }, // [GOAL-SYNC-HEAL 2026-07-08] fallback aligné canon menu_frites +1,50 €
    { id: 'boisson', name: 'Ajouter Boisson', desc: 'Boisson uniquement', price: formulePrice('f-boisson', 1.90), image: FORMULA_CHOICE_IMAGES.boisson }, // [GOAL-SYNC-HEAL 2026-07-08] fallback aligné canon menu_boisson +1,00 €
    { id: 'none',    name: 'Sans formule',   desc: 'Plat seul',         price: 0,    image: null },
  ];
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        Ajoute frites et/ou boisson à ton plat
      </p>
      <div role="radiogroup" aria-label="Faire un menu" style={{ display: 'grid', gap: 10 }}>
        {options.map(o => {
          const on = choice === o.id;
          return (
            <ChoiceCard key={o.id} on={on} onPick={() => pick(o.id)} ariaRole="radio">
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1 }}>
                {o.image
                  ? <img
                      data-testid={`formula-choice-image-${o.id}`}
                      className="lc-wizard-choice-image"
                      src={o.image}
                      alt=""
                      aria-hidden="true"
                      onError={(e) => { e.currentTarget.dataset.imageError = 'true'; }}
                    />
                  : (window.NeutralAsset
                    ? <window.NeutralAsset
                        label="Sans formule — aucune formule sélectionnée"
                        style={{ width: 44, height: 44, borderRadius: 10, flexShrink: 0 }}
                      />
                    : null)}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                  <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--ink)' }}>{o.name}</span>
                  <span style={{ fontSize: 11, color: 'var(--gray-4)' }}>{o.desc}</span>
                </div>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                {o.price > 0 && (
                  <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--ink)', fontWeight: 700 }}>
                    +{o.price.toFixed(2).replace('.', ',')}€
                  </span>
                )}
                <span aria-hidden="true" style={{ width: 18, height: 18, borderRadius: 999, border: on ? '5px solid var(--orange)' : '2px solid var(--gray-2)', background: '#fff' }}/>
              </div>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 6 — Drink (cascade quand menu = full ou boisson)
// ============================================================================
function ScreenStepDrink({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const drinkId = selections.drinkId;
  const pick = (id) => setSelections(s => ({ ...s, drinkId: id }));
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        1 boisson au choix incluse dans ta formule
      </p>
      <div role="radiogroup" aria-label="Choix boisson" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 10 }}>
        {lcMenu.formuleDrinks.filter(d => d.available !== false).map(d => {
          const on = drinkId === d.id;
          return (
            <ChoiceCard key={d.id} on={on} onPick={() => pick(d.id)} ariaRole="radio">
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, width: '100%', padding: '4px 0' }}>
                {/* Phase 6.A real-asset thumb (kiosk drink png) */}
                {d.image
                  ? <img src={d.image} alt="" aria-hidden="true" style={{ width: 56, height: 56, objectFit: 'contain', flexShrink: 0 }}/>
                  : (window.NeutralAsset ? <window.NeutralAsset label={`${d.name} — photo indisponible`} style={{ width: 56, height: 56, borderRadius: 8, flexShrink: 0 }} /> : null)}
                <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--ink)', textAlign: 'center' }}>{d.name}</span>
              </div>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 7 — Frites style (cascade quand menu = full/frites OU item.has_frites_style)
// ============================================================================
function ScreenStepFritesStyle({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const styleId = selections.fritesStyleId;
  const pick = (id) => setSelections(s => ({ ...s, fritesStyleId: id }));
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        Style de frites (Nature gratuit, upgrades payants)
      </p>
      <div role="radiogroup" aria-label="Style de frites" style={{ display: 'grid', gap: 10 }}>
        {lcMenu.fritesStyles.map(fs => {
          const on = styleId === fs.id;
          return (
            <ChoiceCard key={fs.id || 'nature'} on={on} onPick={() => pick(fs.id)} ariaRole="radio">
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1 }}>
                {/* Phase 6.A real-asset thumb (kiosk frites/cheddar png) */}
                {fs.image
                  ? <img src={fs.image} alt="" aria-hidden="true" style={{ width: 40, height: 40, objectFit: 'cover', borderRadius: 8, flexShrink: 0 }}/>
                  : (window.NeutralAsset ? <window.NeutralAsset label={`${fs.name} — photo indisponible`} style={{ width: 40, height: 40, borderRadius: 8, flexShrink: 0 }} /> : null)}
                <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--ink)' }}>{fs.name}</span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                {fs.price > 0 && (
                  <span style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--ink)', fontWeight: 700 }}>
                    +{fs.price.toFixed(2).replace('.', ',')}€
                  </span>
                )}
                <span aria-hidden="true" style={{ width: 18, height: 18, borderRadius: 999, border: on ? '5px solid var(--orange)' : '2px solid var(--gray-2)', background: '#fff' }}/>
              </div>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 8 — Frites sauce (cascade quand menu = full/frites)
// Réutilise ScreenStepSauce avec sauceField='fritesSauceIds'
// ============================================================================
function ScreenStepFritesSauce(props) {
  return <ScreenStepSauce {...props} sauceField="fritesSauceIds"/>;
}

// ============================================================================
// STEP — Bol Suppléments (optionnel · 4 options Oignon frais / Jambon / Champignons / Boule gratinée +2€)
// [MOBILE-REALIGNMENT 2026-05-16] Bols custom composer. Pool = SUPPLEMENTS_BOLS (mobile/data/menu.js).
// Mirrors kiosk extra_group='supplement_bol' DB shape.
// ============================================================================
function ScreenStepBolSupplements({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  // [GOAL-SYNC 2026-07-08] « Option Gratiné » (sb-gratine, riz_only) visible UNIQUEMENT sur le Bol Riz (slug 'bol-riz').
  const isBolRiz = item.slug === 'bol-riz';
  const supplementsBols = (lcMenu.supplementsBols || []).filter(s => {
    if (!lcOffered('extras', s.name) && !lcRupture('extras', s.name)) return false;
    if ((s.riz_only || s.id === 'sb-gratine') && !isBolRiz) return false;
    return true;
  });
  const bolSupplementIds = selections.bolSupplementIds || [];
  // [2026-11-15] plafond et répétition lus dans le profil serveur (viande en plus comprise — R5-002).
  const max = lcSupMax();
  const repeat = lcSupRepeat();
  const used = lcSupTotal(selections);
  const nudge = useNudge();
  const add = (id) => setSelections(s => (lcSupTotal(s) >= max ? s : { ...s, bolSupplementIds: [...(s.bolSupplementIds || []), id] }));
  const remove = (id) => setSelections(s => { const a = (s.bolSupplementIds || []).slice(); const k = a.lastIndexOf(id); if (k >= 0) a.splice(k, 1); return { ...s, bolSupplementIds: a }; });
  return (
    <div style={{ padding: '16px 20px 120px' }}>
      <CapMeter used={used} max={max} label={repeat ? 'Optionnel · touche deux fois pour doubler' : 'Optionnel · personnalise ton bol'} nudge={nudge}/>
      <div role="group" aria-label="Suppléments bol" style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
        {supplementsBols.map(s => {
          const isGratine = s.id === 'sb-gratine' || s.id === 'sb-boule-gratinee';
          return (
            <QtyRow key={s.id} rupture={!lcOffered('extras', s.name)} testid={`bol-supp-${s.id}`} image={s.image} name={s.name} priceLabel={`+ ${s.price.toFixed(2).replace('.', ',')} €`}
              badge={isGratine ? <span style={{ marginLeft: 6, fontSize: 10, padding: '2px 6px', background: 'var(--orange)', color: '#fff', borderRadius: 4, fontWeight: 700, letterSpacing: '0.05em' }}>POPULAIRE</span> : null}
              count={lcCount(bolSupplementIds, s.id)} repeat={repeat} canAdd={used < max}
              onAdd={() => add(s.id)} onRemove={() => remove(s.id)}
              onBlocked={() => nudge.nudge(!lcOffered('extras', s.name) ? `${s.name} est en rupture aujourd'hui` : `Maximum ${max} suppléments par bol — retires-en un pour changer`)}/>
          );
        })}
      </div>
      <ExtraMeatSection item={item} selections={selections} setSelections={setSelections} nudge={nudge}/>
    </div>
  );
}

// ============================================================================
// STEP — Bol Drink (optionnel · 1 boisson du catalogue Boissons, prix unitaire)
// [MOBILE-REALIGNMENT 2026-05-16] Bols custom composer addon role=drink (optionnel).
// "Aucune boisson" = skip choice ; sinon picker like ScreenStepDrink avec prix affiché.
// ============================================================================
function ScreenStepBolDrink({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const bolDrinkId = selections.bolDrinkId; // null = explicit "no drink", undefined = not yet picked
  const pick = (id) => setSelections(s => ({ ...s, bolDrinkId: id }));
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        Optionnel · ajoute une boisson à ton bol (prix catalogue)
      </p>
      <div role="radiogroup" aria-label="Boisson optionnelle" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 10 }}>
        {/* "Aucune boisson" option first */}
        <ChoiceCard on={bolDrinkId === null} onPick={() => pick(null)} ariaRole="radio">
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, width: '100%', padding: '4px 0' }}>
            {window.NeutralAsset && <window.NeutralAsset label="Aucune boisson — aucune boisson sélectionnée" style={{ width: 56, height: 56, borderRadius: 8, flexShrink: 0 }} />}
            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--ink)', textAlign: 'center' }}>Aucune boisson</span>
          </div>
        </ChoiceCard>
        {lcMenu.formuleDrinks.filter(d => d.available !== false).map(d => {
          const on = bolDrinkId === d.id;
          const price = lcMenu.priceForDrinkAddon ? lcMenu.priceForDrinkAddon(d.id) : 1.50;
          return (
            <ChoiceCard key={d.id} on={on} onPick={() => pick(d.id)} ariaRole="radio">
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, width: '100%', padding: '4px 0' }}>
                {d.image
                  ? <img src={d.image} alt="" aria-hidden="true" style={{ width: 56, height: 56, objectFit: 'contain', flexShrink: 0 }}/>
                  : (window.NeutralAsset ? <window.NeutralAsset label={`${d.name} — photo indisponible`} style={{ width: 56, height: 56, borderRadius: 8, flexShrink: 0 }} /> : null)}
                <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--ink)', textAlign: 'center' }}>{d.name}</span>
                <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--ink)', fontWeight: 700 }}>
                  +{price.toFixed(2).replace('.', ',')}€
                </span>
              </div>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// Note du client pour CE produit — la cuisine la lit sur le ticket ([Note : …], api/client.js).
// [2026-11-15] Un seul composant pour l'assistant et l'ajout direct ; des raccourcis écrivent les demandes
// les plus courantes à la place du client (un toucher ajoute, un second retire).
// ============================================================================
const LC_NOTE_MAX = 190;
const LC_NOTE_SHORTCUTS = ['Bien cuit', 'Peu de sauce', 'Coupé en deux'];
function NoteField({ value, onChange, shortcuts = [], placeholder = 'Une précision pour la cuisine ?' }) {
  const v = value || '';
  const [open, setOpen] = useState_w(!!v);
  const clip = (t) => Array.from(t).slice(0, LC_NOTE_MAX).join('');
  // Un raccourci est « posé » si un SEGMENT de la note (entre virgules) COMMENCE par lui : « bien cuit et sans tomate »
  // garde la puce allumée, « pas bien cuit » ne l'allume pas ; le retirer n'enlève que ces mots-là.
  const segments = () => v.split(',').map(x => x.trim()).filter(Boolean);
  const porte = (seg, c) => { const a = seg.toLowerCase(), b = c.toLowerCase(); return a === b || a.indexOf(b + ' ') === 0; };
  const has = (c) => segments().some(x => porte(x, c));
  const [plein, setPlein] = useState_w(false);
  useEffect_w(() => { if (!plein) return undefined; const t = setTimeout(() => setPlein(false), 2400); return () => clearTimeout(t); }, [plein]);
  const toggle = (c) => {
    if (window.lcHaptic) window.lcHaptic(8);
    if (has(c)) { onChange(segments().map(x => porte(x, c) ? x.slice(c.length).trim() : x).filter(Boolean).join(', ')); return; }
    const next = segments().concat([segments().length ? c.toLowerCase() : c]).join(', ');
    if (Array.from(next).length <= LC_NOTE_MAX) onChange(next); else setPlein(true);
  };
  const len = Array.from(v).length;
  return (
    <div className="lc-note" data-testid="note-field">
      <div className="lc-note__head">
        <span className="lc-note__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
        <label htmlFor="lc-recap-instructions" className="lc-note__label">Une petite note ? <span>(optionnel)</span></label>
      </div>
      {shortcuts.length > 0 && (
        <div className="lc-note__chips" role="group" aria-label="Demandes courantes">
          {shortcuts.map(c => (
            <button type="button" key={c} className={'lc-note__chip' + (has(c) ? ' is-on' : '')} aria-pressed={has(c) ? 'true' : 'false'} onClick={() => { setOpen(true); toggle(c); }}>{c}</button>
          ))}
        </div>
      )}
      <textarea
        id="lc-recap-instructions"
        data-testid="recap-instructions"
        rows={open || v ? 3 : 1}
        placeholder={placeholder}
        value={v}
        onFocus={() => setOpen(true)}
        onChange={(e) => onChange(clip(e.target.value))}
        className="lc-note__input"
      />
      <div aria-live="polite" className={'lc-note__count' + (len >= LC_NOTE_MAX || plein ? ' is-full' : '')} style={{ visibility: open || v || plein ? 'visible' : 'hidden' }}>
        {plein ? 'Plus de place dans la note · ' : ''}{len} / {LC_NOTE_MAX}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 9 — Recap (composition résumé + total + Ajouter au panier)
// ============================================================================
function ScreenStepRecap({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const meatLabels = lcGroupLabels((selections.meatIds || []).map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean));
  // [GOAL-SYNC 2026-07-08] lookup multi-pools (sauces génériques + bolSauces 'bs-*')
  const sauceLabels = (selections.sauceIds || []).map(id => sauceNameFor(lcMenu, id)).filter(Boolean);
  // [GOAL-SYNC 2026-07-08] pain + viandes supplémentaires
  const painLabel = selections.painId ? ((lcMenu.pains || []).find(p => p.id === selections.painId) || {}).name : null;
  const extraMeatLabels = lcGroupLabels((selections.extraMeatIds || []).map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean));
  const cruditeIds = selections.cruditeIds || lcMenu.defaultCruditeIds();
  const cruditeAll = lcMenu.crudites.filter(c => c.default).map(c => c.id); // « retirées » = servies par défaut seulement
  const cruditeRemoved = cruditeAll.filter(id => !cruditeIds.includes(id)).map(id => (lcMenu.crudites.find(c => c.id === id) || {}).name).filter(Boolean);
  const cruditeKept = lcMenu.crudites.filter(c => cruditeIds.includes(c.id)).map(c => c.name);
  const supLabels = lcGroupLabels((selections.supplementIds || []).map(id => (lcMenu.supplements.find(s => s.id === id) || {}).name).filter(Boolean));
  const bolSupLabels = lcGroupLabels((selections.bolSupplementIds || []).map(id => (lcMenu.supplementsBols.find(s => s.id === id) || {}).name).filter(Boolean));
  const bolDrinkLabel = selections.bolDrinkId
    ? (lcMenu.formuleDrinks.find(d => d.id === selections.bolDrinkId) || {}).name
    : (selections.bolDrinkId === null ? 'Aucune' : null);
  const drinkLabel = selections.drinkId ? (lcMenu.formuleDrinks.find(d => d.id === selections.drinkId) || {}).name : null;
  const styleLabel = selections.fritesStyleId !== undefined
    ? (lcMenu.fritesStyles.find(fs => fs.id === selections.fritesStyleId) || {}).name
    : null;
  const fritesSauceLabels = (selections.fritesSauceIds || []).map(id => sauceNameFor(lcMenu, id)).filter(Boolean);
  // [MOBILE-REALIGNMENT 2026-05-16] Show locked sauce for items with sauce_locked
  // (e.g. Cayenne → "Sauce fromagère maison (incluse)").
  const sauceLockedLabel = item.sauce_locked ? `Sauce ${item.sauce_locked} maison (incluse)` : null;
  // [MOBILE-REALIGNMENT 2026-05-16] Show fixed meat for bols (item.bol_meat_fixed)
  const bolMeatLabel = item.bol_meat_fixed || null;
  const bolBaseLabel = item.has_bol_wizard
    ? (item.slug && item.slug.indexOf('bowl-frites-') === 0 ? 'Frites' :
       item.slug && item.slug.indexOf('bowl-riz-') === 0 ? 'Riz basmati' : null)
    : null;
  const menuLabel = (() => {
    // [GOAL-SYNC 2026-07-08] TOUS les prix formule lus depuis FORMULES (une seule
    // source — fallback aligné canon f-menu 2,50 €, plus jamais 3 €).
    const _formules = ((window.LC && window.LC.menu && window.LC.menu.formules) || []);
    const _fPrice = function (id, fallback) {
      const f = _formules.find(function (x) { return x.id === id; });
      return ((f ? f.price : fallback)).toFixed(2).replace('.', ',').replace(',00', '');
    };
    if (selections.menuChoice === 'full') return 'Menu (Frites + Boisson) +' + _fPrice('f-menu', 2.50) + '€';
    if (selections.menuChoice === 'frites') return 'Ajouter Frites +' + _fPrice('f-frites', 1.90) + '€'; // [GOAL-SYNC-HEAL 2026-07-08] fallback aligné canon menu_frites +1,50 €
    if (selections.menuChoice === 'boisson') return 'Ajouter Boisson +' + _fPrice('f-boisson', 1.90) + '€'; // [GOAL-SYNC-HEAL 2026-07-08] fallback aligné canon menu_boisson +1,00 €
    // [test-e2e fix B-002/B-003 round-2 2026-05-11] surface "Sans formule" explicitly so
    // the user can confirm the no-formule choice on the recap.
    if (selections.menuChoice === 'none') return 'Sans formule';
    return null;
  })();
  const qty = selections.qty || 1;
  const setQty = (q) => setSelections(s => ({ ...s, qty: Math.max(1, q) }));

  // [MASSIVE-LOGIC HEAL 2026-05-17 P0 FIC 1169/2011] Aggregate allergens across item +
  // selected supplements + bol supplements + drink + bol drink (legal disclosure compliance).
  // Previously recap only surfaced item.allergens — supplements/drinks selected silently dropped.
  const aggregatedAllergens = (() => {
    const set = new Set(item.allergens || []);
    // Supplements pool IDs are 'sup-*' (e.g. sup-cheddar), distinct from catalog item
    // slugs 'supp-*'. Look up allergens on the SUPPLEMENTS pool directly (now populated
    // post MASSIVE-LOGIC HEAL 2026-05-17 P0).
    (selections.supplementIds || []).forEach(id => {
      const s = lcMenu.supplements.find(x => x.id === id);
      ((s && s.allergens) || []).forEach(a => set.add(a));
    });
    (selections.bolSupplementIds || []).forEach(id => {
      const s = lcMenu.supplementsBols.find(x => x.id === id);
      ((s && s.allergens) || []).forEach(a => set.add(a));
    });
    if (selections.drinkId) {
      const d = lcMenu.items.find(x => x.slug === selections.drinkId || x.id === selections.drinkId);
      ((d && d.allergens) || []).forEach(a => set.add(a));
    }
    if (selections.bolDrinkId && selections.bolDrinkId !== null) {
      const drinkSlugMap = { 'd-coca': 'coca', 'd-coca-zero': 'coca-zero', 'd-fanta': 'fanta', 'd-sprite': 'sprite', 'd-oasis': 'oasis', 'd-orangina': 'orangina', 'd-eau': 'eau-plate', 'd-capri': 'capri-sun' };
      const slug = drinkSlugMap[selections.bolDrinkId];
      if (slug) {
        const d = lcMenu.items.find(x => x.slug === slug);
        ((d && d.allergens) || []).forEach(a => set.add(a));
      }
    }
    return Array.from(set);
  })();

  // [test-e2e fix B-002/B-003 round-2 2026-05-11] derive active steps from the template
  // state machine so the recap surfaces a row for EVERY step the user traversed, with a
  // sentinel value ("Toutes", "Aucun", "Sans formule") when the selection equals the
  // default. Previous render dropped rows whose value was empty/default, which made
  // Le Terminator and Cheese Burger recaps omit CRUDITÉS / SUPPLÉMENTS / FORMULE rows
  // even though the user had explicitly walked those steps.
  const activeSteps = computeActiveSteps(item, selections);
  const has = (k) => activeSteps.includes(k);

  const Row = ({ label, value }) => value ? (
    <div className="lc-recap-row" style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '12px 16px', borderBottom: '1px solid var(--gray-1)' }}>
      <span style={{ fontSize: 12, color: 'var(--gray-4)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', flexShrink: 0 }}>{label}</span>
      <span style={{ fontSize: 13, color: 'var(--ink)', fontWeight: 600, textAlign: 'right', maxWidth: '60%' }}>{value}</span>
    </div>
  ) : null;

  return (
    <div style={{ padding: '20px 20px 210px' }}>
      <div style={{ background: 'var(--cream)', borderRadius: 16, padding: '14px 0 0', overflow: 'hidden' }}>
        <div style={{ padding: '0 16px 12px', display: 'flex', alignItems: 'center', gap: 10 }}>
          <Slot id={`recap-${item.id}`} w={44} h={44} radius={10} placeholder={item.name} src={item.image} alt={item.name} fit="contain" />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 16, lineHeight: 1.15, fontFamily: 'var(--font-display)', textTransform: 'uppercase' }}>{item.name}</div>
            <div style={{ fontSize: 11, color: 'var(--gray-4)' }}>Récapitulatif de ta commande</div>
          </div>
          {/* [MOBILE-EXCELLENCE-PHASE13] Disclosure textuelle sur le récapitulatif.
              [MASSIVE-LOGIC HEAL 2026-05-17] uses aggregatedAllergens (item + supps + drinks)
              so every selected component remains covered by the legal disclosure. */}
        </div>
        <div style={{ padding: '0 16px 12px' }}>
          {window.AllergenBadge && <window.AllergenBadge allergens={aggregatedAllergens} size="lg"/>}
        </div>
        {/* [MOBILE-REALIGNMENT 2026-05-16] Bol pre-filled rows : base + viande fixed by item */}
        {bolBaseLabel && <Row label="Base" value={bolBaseLabel}/>}
        {bolMeatLabel && <Row label="Viande" value={bolMeatLabel}/>}
        {sauceLockedLabel && !has(STEP.SAUCE) && <Row label="Sauce" value={sauceLockedLabel}/>}
        {/* [GOAL-SYNC 2026-07-08] rangée Pain/Galette */}
        {has(STEP.PAIN) && <Row label="Pain" value={painLabel || '—'}/>}
        {has(STEP.VIANDES) && <Row label="Viandes" value={meatLabels.length ? meatLabels.join(' · ') : '—'}/>}
        {has(STEP.SAUCE) && <Row label="Sauce" value={sauceLabels.length ? sauceLabels.join(' / ') : '—'}/>}
        {has(STEP.CRUDITES) && (
          cruditeRemoved.length > 0
            ? <Row label="Crudités retirées" value={cruditeRemoved.join(' / ')}/>
            : <Row label="Crudités" value={cruditeKept.length ? 'Toutes (' + cruditeKept.join(' · ') + ')' : 'Toutes'}/>
        )}
        {has(STEP.SUPPLEMENTS) && <Row label="Suppléments" value={supLabels.length ? supLabels.join(' + ') : 'Aucun'}/>}
        {/* [GOAL-SYNC 2026-07-08] rangée viandes supplémentaires (+2,50 € chacune) */}
        {item.has_extra_meat && extraMeatLabels.length > 0 && <Row label="Viande en plus" value={extraMeatLabels.join(' + ') + ' (+' + (extraMeatPriceOf(lcMenu) * (selections.extraMeatIds || []).length).toFixed(2).replace('.', ',') + ' €)'}/>}
        {has(STEP.BOL_SUPPLEMENTS) && <Row label="Suppléments bol" value={bolSupLabels.length ? bolSupLabels.join(' + ') : 'Aucun'}/>}
        {has(STEP.BOL_DRINK) && <Row label="Boisson bol" value={bolDrinkLabel || 'Aucune'}/>}
        {has(STEP.MENU) && <Row label="Formule" value={menuLabel || 'Sans formule'}/>}
        {has(STEP.DRINK) && <Row label="Boisson" value={drinkLabel || '—'}/>}
        {has(STEP.FRITES_STYLE) && <Row label="Style frites" value={styleLabel || '—'}/>}
        {has(STEP.FRITES_SAUCE) && <Row label="Sauce frites" value={fritesSauceLabels.length ? fritesSauceLabels.join(' / ') : '—'}/>}
      </div>

      {/* [D5 Owner re-cadrage 2026-05-11] Note du client pour ce produit (190 caractères, lue en cuisine). */}
      <NoteField value={selections.instruction} onChange={(t) => setSelections(st => ({ ...st, instruction: t }))} shortcuts={LC_NOTE_SHORTCUTS} placeholder="Ex. sans oignon, bien cuit…"/>

      <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', background: 'var(--ink)', borderRadius: 16 }}>
        <span style={{ color: '#fff', fontSize: 13, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Quantité</span>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <button onClick={() => setQty(qty - 1)} aria-label="Diminuer la quantité" style={{ width: 40, height: 40, borderRadius: 999, background: 'rgba(255,255,255,0.15)', border: 0, color: '#fff', cursor: 'pointer' }}>
            <I.Minus size={14} stroke="#fff"/>
          </button>
          <span aria-live="polite" style={{ color: 'var(--orange)', fontFamily: 'var(--font-display)', fontSize: 24, minWidth: 24, textAlign: 'center' }}>{qty}</span>
          <button onClick={() => setQty(qty + 1)} aria-label="Augmenter la quantité" style={{ width: 40, height: 40, borderRadius: 999, background: 'var(--orange)', border: 0, color: '#fff', cursor: 'pointer' }}>
            <I.Plus size={14} stroke="#fff"/>
          </button>
        </div>
      </div>
    </div>
  );
}

// ============================================================================
// Composition snapshot builder (cart line item, kiosk-aligned shape)
// ============================================================================
function buildLineItem(item, selections) {
  const lcMenu = window.LC.menu;
  const meatLabels = (selections.meatIds || []).map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean);
  // [GOAL-SYNC 2026-07-08] lookup multi-pools (sauces génériques + bolSauces 'bs-*')
  const sauceLabels = (selections.sauceIds || []).map(id => sauceNameFor(lcMenu, id)).filter(Boolean);
  // [GOAL-SYNC 2026-07-08] pain (has_pain_choice) + viandes supplémentaires (has_extra_meat)
  const painLabel = selections.painId ? ((lcMenu.pains || []).find(p => p.id === selections.painId) || {}).name || null : null;
  const extraMeatLabels = (selections.extraMeatIds || []).map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean);
  const cruditeIds = selections.cruditeIds || lcMenu.defaultCruditeIds();
  // [2026-11-14] « Sans … » seulement pour un produit AVEC crudités, et seulement les crudités servies par
  // défaut ; une option ajoutée (Oignons cuits) s'affiche « Avec … » (avant : « Sans oignons cuits » partout,
  // même sur des frites — relecture adverse A2-008).
  const cruditeRemoved = item.has_crudites ? lcMenu.crudites.filter(c => c.default && !cruditeIds.includes(c.id)).map(c => c.name) : [];
  const cruditeAdded = item.has_crudites ? lcMenu.crudites.filter(c => !c.default && cruditeIds.includes(c.id)).map(c => c.name) : [];
  const supLabels = (selections.supplementIds || []).map(id => (lcMenu.supplements.find(s => s.id === id) || {}).name).filter(Boolean);
  const bolSupLabels = (selections.bolSupplementIds || []).map(id => (lcMenu.supplementsBols.find(s => s.id === id) || {}).name).filter(Boolean);
  const bolDrinkLabel = selections.bolDrinkId
    ? (lcMenu.formuleDrinks.find(d => d.id === selections.bolDrinkId) || {}).name
    : null;
  const drinkLabel = selections.drinkId ? (lcMenu.formuleDrinks.find(d => d.id === selections.drinkId) || {}).name : null;
  const styleLabel = selections.fritesStyleId !== undefined
    ? (lcMenu.fritesStyles.find(fs => fs.id === selections.fritesStyleId) || {}).name
    : null;
  const fritesSauceLabels = (selections.fritesSauceIds || []).map(id => sauceNameFor(lcMenu, id)).filter(Boolean);
  const summary = [];
  // [GOAL-SYNC 2026-07-08] pain en tête de résumé (base du sandwich)
  if (painLabel) summary.push(painLabel);
  // [MOBILE-REALIGNMENT 2026-05-16] Bol pre-filled context (base + viande fixed)
  if (item.has_bol_wizard) {
    const baseLabel = item.slug && item.slug.indexOf('bowl-frites-') === 0 ? 'Frites' :
                      item.slug && item.slug.indexOf('bowl-riz-') === 0 ? 'Riz' : null;
    if (baseLabel) summary.push(baseLabel);
    if (item.bol_meat_fixed) summary.push(item.bol_meat_fixed);
  }
  if (meatLabels.length) summary.push(lcGroupLabels(meatLabels).join(' + '));
  if (sauceLabels.length) summary.push('Sauce ' + sauceLabels.map(l => String(l).replace(/^sauce\s+/i, '')).join('/'));
  if (cruditeRemoved.length) summary.push('Sans ' + cruditeRemoved.map(s => s.toLowerCase()).join('/'));
  if (cruditeAdded.length) summary.push('Avec ' + cruditeAdded.map(s => s.toLowerCase()).join('/'));
  if (supLabels.length) summary.push('+ ' + lcGroupLabels(supLabels).join(' + '));
  // [GOAL-SYNC 2026-07-08] viandes supplémentaires (+2,50 € chacune) dans le résumé cuisine
  if (extraMeatLabels.length) summary.push('Viande en plus ' + lcGroupLabels(extraMeatLabels).join(' + '));
  if (bolSupLabels.length) summary.push('+ ' + lcGroupLabels(bolSupLabels).join(' + '));
  if (bolDrinkLabel) summary.push('🥤 ' + bolDrinkLabel);
  if (selections.menuChoice && selections.menuChoice !== 'none') {
    const mLabel = selections.menuChoice === 'full' ? '🍽 Menu' : selections.menuChoice === 'frites' ? '🍟 Frites' : '🥤 Boisson';
    summary.push(mLabel);
  }
  if (drinkLabel) summary.push('🥤 ' + drinkLabel);
  if (styleLabel && styleLabel !== 'Nature') summary.push('🧀 ' + styleLabel);
  if (fritesSauceLabels.length) summary.push('🍟 sauce ' + fritesSauceLabels.join('/'));

  const qty = selections.qty || 1;
  const unitPrice = computeTotal(item, { ...selections, qty: 1 });
  const lineTotal = unitPrice * qty;

  return {
    ...item,
    // [GOAL-SYNC 2026-07-08] pain + viandes supplémentaires (compat : champs ADDITIFS)
    painId: selections.painId || null,
    painLabel: painLabel || null,
    extraMeatIds: selections.extraMeatIds || [],
    extraMeatLabels,
    meatIds: selections.meatIds || [],
    meatLabels,
    sauceIds: selections.sauceIds || [],
    sauceLabels,
    cruditeIds,
    cruditeRemoved,
    supplementIds: selections.supplementIds || [],
    supLabels,
    bolSupplementIds: selections.bolSupplementIds || [],
    bolSupLabels,
    bolDrinkId: selections.bolDrinkId !== undefined ? selections.bolDrinkId : null,
    bolDrinkLabel,
    menuChoice: selections.menuChoice || 'none',
    drinkId: selections.drinkId || null,
    drinkLabel,
    fritesStyleId: selections.fritesStyleId !== undefined ? selections.fritesStyleId : null,
    styleLabel,
    fritesSauceIds: selections.fritesSauceIds || [],
    fritesSauceLabels,
    // [D5 Owner re-cadrage 2026-05-11] instruction spéciale recap → ligne cart
    instruction: Array.from(selections.instruction || '').slice(0, 190).join(''),
    composition_summary: summary.join(' · ') + ((selections.instruction && selections.instruction.length) ? ' · 📝 ' + Array.from(selections.instruction).slice(0, 60).join('') + (Array.from(selections.instruction).length > 60 ? '…' : '') : ''),
    sups: selections.supplementIds || [],
    qty,
    unitPrice,
    lineTotal,
    price: unitPrice,
  };
}

// ============================================================================
// Main wizard shell — state machine
// ============================================================================
function ScreenItemWizard({ go, itemId, addToCart }) {
  const lcMenu = window.LC.menu;
  const item = lcMenu.findItem(itemId);
  const headingRef = useRef_w(null);
  // [2026-11-14 R8] L'étape courante est suivie par son NOM : quand les options du serveur arrivent, la liste
  // d'étapes change et l'index pointait sur l'étape suivante (étape viande sautée).
  // [2026-11-14 R9-001] Étape voulue en ÉTAT (pas en ref) : changer de nom d'étape doit redessiner, même si
  // l'index numérique reste le même (sinon « Suivant » ne faisait plus rien).
  const [wantedKey, setWantedKey] = useState_w(null);

  const [selections, setSelections] = useState_w(() => {
    // [MOBILE-REALIGNMENT 2026-05-16] Pre-fill defaults from composer profile + item flags
    // (kiosk parity: KW.vue initSelections — sauce default + bol context).
    const init = {
      painId: undefined,        // [GOAL-SYNC 2026-07-08] Pain/Galette (has_pain_choice)
      meatIds: [],
      sauceIds: [],
      extraMeatIds: [],         // [GOAL-SYNC 2026-07-08] viandes supplémentaires +2,50 €
      cruditeIds: lcMenu.defaultCruditeIds(),
      supplementIds: [],
      bolSupplementIds: [],     // Bols custom composer
      bolDrinkId: undefined,    // null = explicit "no drink", undefined = not yet picked
      menuChoice: undefined,    // undefined = not yet picked, distinct from 'none'
      drinkId: undefined,
      fritesStyleId: undefined,
      fritesSauceIds: [],
      qty: 1,
    };
    // [GOAL-SYNC 2026-07-08] Pain par défaut = Pain (pain-classique), parité web
    // wizard-v2.jsx:72 defaultValue:'pain-classique'.
    if (item && item.has_pain_choice) {
      const pains = lcMenu.pains || [];
      const defaultPain = pains.find(p => p.is_default) || pains.find(p => p.id === 'pain-classique') || pains[0];
      if (defaultPain) init.painId = defaultPain.id;
    }
    // Bol sauce default (e.g. bowl-frites-curry → 'Curry' sauce pre-selected)
    if (item && item.bol_sauce_default) {
      const defaultSauce = lcMenu.sauces.find(s => s.name === item.bol_sauce_default);
      if (defaultSauce) init.sauceIds = [defaultSauce.id];
    }
    // [GOAL-SYNC 2026-07-08] sauce_default pré-sélectionnée (ex. Cayenne →
    // 'Sauce fromagère maison'). Recherche dans le pool DU template (bol →
    // bolSauces, sinon sauces génériques) — match exact PUIS inclusion insensible
    // à la casse (pool 'Fromagère maison' vs canon 'Sauce fromagère maison').
    if (item && item.sauce_default && !init.sauceIds.length) {
      // [2026-11-14] Pool générique d'abord (l'étape sauce du bol affiche désormais la liste serveur complète).
      const pool = (lcMenu.sauces || []).concat(wizardTemplateFor(item) === 'bol' ? (lcMenu.bolSauces || []) : []);
      const wanted = String(item.sauce_default).toLowerCase();
      const defaultSauce = pool.find(s => s.name === item.sauce_default)
        || pool.find(s => wanted.indexOf(String(s.name).toLowerCase()) !== -1 || String(s.name).toLowerCase().indexOf(wanted) !== -1);
      if (defaultSauce) init.sauceIds = [defaultSauce.id];
    }
    // [MOBILE-REALIGNMENT 2026-05-16 RED heal P1-6] Pre-select "Nature" (id=null) for
    // Frites items so user is not blocked at first step if they accept the default.
    // FRITES_STYLES marks Nature with is_default=true ; mobile honors that intent.
    if (item && item.has_frites_style) {
      const defaultStyle = lcMenu.fritesStyles.find(fs => fs.is_default);
      if (defaultStyle !== undefined) init.fritesStyleId = defaultStyle.id; // null for Nature
    }
    return init;
  });

  const [stepIndex, setStepIndex] = useState_w(0);
  // [UI/UX 2026-11-15] Sens du pas (suivant / retour) : l'étape glisse d'où elle vient.
  const [stepDir, setStepDir] = useState_w('next');
  const screenRef = useScreenScrollReset(`${itemId}:${stepIndex}`);

  // [2026-11-14] Options du serveur pour ce produit (voir lcOffered).
  const [itemOptions, setItemOptions] = useState_w(() => ((window.LC.itemOptions || {})[itemId]) || null);
  // [2026-11-14 R7-002] On attend les options du serveur AVANT de laisser avancer (sinon une étape viande
  // pouvait sauter si la réponse arrivait après le 1er « Suivant ») ; 5 s au plus, puis repli local.
  const [optionsWait, setOptionsWait] = useState_w(() => !!(item && !((window.LC.itemOptions || {})[itemId]) && window.LC.mobileApi && window.LC.mobileApi.optionNames
    && (item.server_id || (window.LC.catalog && window.LC.catalog.status === 'pending'))));
  useEffect_w(() => {
    let alive = true;
    const api = window.LC.mobileApi;
    // [2026-11-15] Toujours relu à l'ouverture (la fiche est gardée 45 s par api/client.js) : une rupture posée en
    // caisse entre deux ouvertures du même plat doit apparaître ; l'ancienne réponse sert seulement d'affichage immédiat.
    if (item && item.server_id && api && api.optionNames) {
      const t = setTimeout(() => { if (alive) setOptionsWait(false); }, 5000);
      api.optionNames(item.server_id).then(o => {
        window.LC.itemOptions = window.LC.itemOptions || {};
        window.LC.itemOptions[itemId] = o;
        if (alive) { setItemOptions(o); setOptionsWait(false); }
      }).catch(() => { if (alive) setOptionsWait(false); /* hors ligne : tout reste affiché */ }).finally(() => clearTimeout(t));
      return () => { alive = false; clearTimeout(t); };
    }
    // Carte pas encore relue (pas d'id serveur) : on patiente tant qu'elle est en cours, sinon repli local.
    if (!(window.LC.catalog && window.LC.catalog.status === 'pending')) { setOptionsWait(false); return () => { alive = false; }; }
    const t2 = setTimeout(() => { if (alive) setOptionsWait(false); }, 5000); // carte injoignable : repli local
    return () => { alive = false; clearTimeout(t2); };
  }, [itemId, item && item.server_id]);
  window.LC.currentItemOptions = itemOptions;
  // [2026-11-15 relecture adverse P1] Un choix fait sur une réponse périmée (ou pré-coché : sauce par défaut) puis
  // signalé en rupture par le serveur est RETIRÉ de la sélection, et le client est prévenu — sinon la carte cochée
  // masquait l'étiquette « Rupture » et la ligne partait au panier pour être refusée à la commande.
  const [ruptureNote, setRuptureNote] = useState_w(null);
  useEffect_w(() => {
    if (!itemOptions || !itemOptions.rupture) return undefined;
    const retires = [];
    const garde = (ids, pools, kind) => (ids || []).filter(id => {
      let nm = null;
      pools.forEach(pl => { const f = (pl || []).find(x => x.id === id); if (f && !nm) nm = f.name; });
      if (nm && lcRupture(kind, nm) && !lcOffered(kind, nm)) { if (retires.indexOf(nm) === -1) retires.push(nm); return false; }
      return true;
    });
    const nettoie = (st) => ({
      meatIds: garde(st.meatIds, [lcMenu.meats], 'variations'),
      extraMeatIds: garde(st.extraMeatIds, [lcMenu.meats], 'variations'),
      sauceIds: garde(st.sauceIds, [lcMenu.sauces, lcMenu.bolSauces], 'variations'),
      supplementIds: garde(st.supplementIds, [lcMenu.supplements], 'extras'),
      bolSupplementIds: garde(st.bolSupplementIds, [lcMenu.supplementsBols], 'extras'),
    });
    nettoie(selections); // relevé sur l'état courant : c'est lui qui décide du message
    // [2026-11-15 relecture adverse, tour 3] Fiche arrivée APRÈS le repli local (réseau lent) : des viandes choisies
    // sur la carte locale restaient dans la sélection alors que le plat n'en demande pas (Suprême de production) ou
    // en demande moins → ligne au panier impossible à envoyer. La sélection est ramenée à ce que la fiche permet.
    const requis = lcRequiredMeats(item);
    const plusMax = lcExtraMeatCap(item);
    const ajuste = (st) => ({ meatIds: (st.meatIds || []).slice(0, requis), extraMeatIds: (st.extraMeatIds || []).slice(0, plusMax) });
    const trop = (selections.meatIds || []).length > requis || (selections.extraMeatIds || []).length > plusMax;
    if (trop) setSelections(st => ({ ...st, ...ajuste(st) }));
    if (!retires.length) return undefined;
    setSelections(st => ({ ...st, ...nettoie(st) }));
    setRuptureNote(retires.join(', ') + (retires.length > 1 ? ' sont en rupture : choix retirés' : ' est en rupture : choix retiré'));
    const t = setTimeout(() => setRuptureNote(null), 7000);
    return () => clearTimeout(t);
  }, [itemOptions]);

  // If item not found, render error early
  if (!item) {
    return (
      <div ref={screenRef} data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff', padding: 40, paddingTop: 'calc(var(--lc-inset-top) + 60px)', textAlign: 'center' }}>
        <h2 className="lc-display" style={{ fontSize: 26, color: 'var(--ink)' }}>Plat introuvable.</h2>
        <button onClick={() => go('back')} className="lc-btn lc-btn--ink" style={{ marginTop: 20 }}>Retour</button>
      </div>
    );
  }

  // Compute active steps reactively
  const activeSteps = computeActiveSteps(item, selections);

  // Direct-add when no wizard steps (cat 11/12, cat 13 except sauce sup)
  const shouldDirectAdd = activeSteps.length === 0 || (activeSteps.length === 1 && activeSteps[0] === STEP.RECAP);

  if (shouldDirectAdd) {
    // Render a simple "details + add to cart" view (qty stepper)
    return <ScreenItemDirectAdd item={item} selections={selections} setSelections={setSelections} go={go} addToCart={addToCart}/>;
  }

  // Clamp stepIndex if cascade insertion changed steps array — et recalage par nom d'étape (R8).
  // La navigation mémorise le NOM de l'étape voulue (wantedKeyRef) ; la position en découle à chaque rendu.
  const wantedIdx = wantedKey ? activeSteps.indexOf(wantedKey) : -1;
  const clampedIndex = Math.min(wantedIdx >= 0 ? wantedIdx : stepIndex, activeSteps.length - 1);
  const currentKey = activeSteps[clampedIndex];
  const goStep = (idx) => { const i = Math.max(0, Math.min(activeSteps.length - 1, idx)); setStepDir(i < clampedIndex ? 'back' : 'next'); setWantedKey(activeSteps[i]); setStepIndex(i); };
  const isRecap = currentKey === STEP.RECAP;

  // Move focus to step heading on transition (a11y)
  useEffect_w(() => {
    if (headingRef.current) {
      try { headingRef.current.focus({ preventScroll: true }); } catch (e) { /* iOS Safari */ }
    }
  }, [clampedIndex, currentKey]);

  const total = computeTotal(item, selections);
  const valid = canAdvance(currentKey, selections, item);

  const onPrev = () => {
    if (clampedIndex === 0) {
      go('back');
      return;
    }
    goStep(clampedIndex - 1);
  };
  const onNext = () => {
    if (!valid || optionsWait) return;
    if (isRecap) {
      // [2026-11-14 R8] Toutes les étapes sont revérifiées : une étape obligatoire jamais remplie renvoie à elle.
      const manquante = activeSteps.findIndex(k => k !== STEP.RECAP && !canAdvance(k, selections, item));
      if (manquante >= 0) { goStep(manquante); return; }
      // Le pain n'est retenu que si le profil serveur propose l'étape (sinon il n'est pas envoyé).
      const sel = activeSteps.includes(STEP.PAIN) ? selections : { ...selections, painId: undefined };
      addToCart(buildLineItem(item, sel));
      go('cart');
      return;
    }
    goStep(clampedIndex + 1);
  };

  // Hint message for disabled CTA
  const hint = (() => {
    if (ruptureNote) return ruptureNote;
    if (valid) return null;
    if (currentKey === STEP.VIANDES) {
      if (!lcMenu.meats.some(m => lcOffered('variations', m.name))) return 'Plus aucune viande disponible pour ce plat aujourd\'hui';
      const required = lcRequiredMeats(item);
      const remain = required - (selections.meatIds || []).length;
      return `Encore ${remain} viande${remain > 1 ? 's' : ''} à choisir`;
    }
    if (currentKey === STEP.PAIN) return 'Choisis Pain ou Galette';
    if (currentKey === STEP.SAUCE) return 'Choisis ta sauce';
    if (currentKey === STEP.MENU) return 'Choisis ta formule pour continuer';
    if (currentKey === STEP.DRINK) return 'Choisis ta boisson';
    if (currentKey === STEP.FRITES_STYLE) return 'Choisis ton style de frites';
    if (currentKey === STEP.FRITES_SAUCE) return 'Choisis au moins une sauce pour les frites';
    return null;
  })();

  // Render step body
  let body;
  // [2026-11-14 R8-003] Pendant le chargement des options, aucun choix n'est possible (un « Galette » tapé à ce
  // moment pouvait ne jamais être envoyé).
  if (optionsWait) body = <div role="status" style={{ padding: '48px 24px', textAlign: 'center', color: 'var(--gray-4)', fontSize: 14 }}>Chargement des options de {item.name}…</div>;
  else switch (currentKey) {
    case STEP.PAIN:              body = <ScreenStepPain item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.VIANDES:           body = <ScreenStepViandes item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.SAUCE:             body = <ScreenStepSauce item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.CRUDITES:          body = <ScreenStepCrudites item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.SUPPLEMENTS:       body = <ScreenStepSupplements item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.MENU:              body = <ScreenStepMenu item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.DRINK:             body = <ScreenStepDrink item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.FRITES_STYLE:      body = <ScreenStepFritesStyle item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.FRITES_SAUCE:      body = <ScreenStepFritesSauce item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.BOL_SUPPLEMENTS:   body = <ScreenStepBolSupplements item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.BOL_DRINK:         body = <ScreenStepBolDrink item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.RECAP:             body = <ScreenStepRecap item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    default:                     body = <div style={{ padding: 40, textAlign: 'center' }}><p>Étape inconnue : {currentKey}</p></div>;
  }

  const stepTitle = STEP_LABELS[currentKey] || currentKey;

  return (
    <div ref={screenRef} data-screen-label={`09 Item Wizard ${stepTitle}`} style={{ position: 'absolute', inset: 0, background: 'var(--cream)', overflow: 'hidden' }}>
      {/* La bande de la barre d'état prend la couleur de l'en-tête (crème) ; le corps reste sur papier. */}
      <div data-screen-scroll style={{ background: 'var(--paper)' }}>
        <WizardHeader
          stepIndex={clampedIndex}
          stepTotal={activeSteps.length}
          title={stepTitle}
          onBack={onPrev}
          onClose={() => go('back')}
          headingRef={headingRef}
          item={item}
          total={total}
        />
        {/* Keyed content resets each step; mobile navigation stays animation-free. */}
        <div key={currentKey} className="rdw-step">
          <div className={'lc-step-in' + (stepDir === 'back' ? ' lc-step-in--back' : '')}>{body}</div>
        </div>
      </div>
      <WizardCTA
        label={item.available === false ? 'Épuisé pour le moment' : optionsWait ? 'Chargement des options…' : (isRecap ? `Ajouter au panier · ${selections.qty || 1}` : 'Suivant')}
        total={total}
        disabled={!valid || item.available === false || optionsWait}
        hint={hint}
        onClick={onNext}
      />
    </div>
  );
}

// ============================================================================
// Direct-add view (cat 11 desserts, cat 12 boissons, cat 13 plain supplements)
// ============================================================================
function ScreenItemDirectAdd({ item, selections, setSelections, go, addToCart }) {
  const screenRef = useScreenScrollReset(item.id);
  const qty = selections.qty || 1;
  const setQty = (q) => setSelections(s => ({ ...s, qty: Math.max(1, q) }));
  const total = item.price * qty;
  return (
    <div ref={screenRef} data-screen-label="09 Item Direct" style={{ position: 'absolute', inset: 0, background: 'var(--paper)' }}>
      <div className="lc-screen" style={{ paddingTop: 0, paddingBottom: 'calc(var(--lc-safe-bottom) + 110px)' }}>
        <div style={{ position: 'relative', height: 280, background: 'var(--ink)' }}>
          <Slot id={item.thumb} h="100%" radius={0} placeholder={item.name} src={item.image} alt={item.name} fit="contain"/>
          <div style={{ position: 'absolute', top: 12, left: 14, right: 14, display: 'flex', justifyContent: 'space-between', zIndex: 2 }}>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Retour"><I.Back size={20}/></IconBtn>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Fermer"><I.Close size={18}/></IconBtn>
          </div>
        </div>
        <div style={{ padding: '24px 20px' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 32, lineHeight: 0.95 }}>{item.name}</h1>
          <p style={{ marginTop: 10, fontSize: 14, lineHeight: 1.5, color: 'var(--gray-4)' }}>{item.description}</p>
          {/* [MOBILE-EXCELLENCE-PHASE13] Disclosure textuelle sur le détail produit. */}
          {window.AllergenBadge && <window.AllergenBadge allergens={item.allergens} size="lg"/>}
          <NoteField value={selections.instruction} onChange={(t) => setSelections(st => ({ ...st, instruction: t }))} placeholder="Ex. bien frais, sans paille…"/>
          <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', background: 'var(--ink)', borderRadius: 16 }}>
            <span style={{ color: '#fff', fontSize: 13, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Quantité</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <button onClick={() => setQty(qty - 1)} aria-label="Diminuer la quantité" style={{ width: 40, height: 40, borderRadius: 999, background: 'rgba(255,255,255,0.15)', border: 0, color: '#fff', cursor: 'pointer' }}>
                <I.Minus size={14} stroke="#fff"/>
              </button>
              <span aria-live="polite" style={{ color: 'var(--orange)', fontFamily: 'var(--font-display)', fontSize: 24, minWidth: 24, textAlign: 'center' }}>{qty}</span>
              <button onClick={() => setQty(qty + 1)} aria-label="Augmenter la quantité" style={{ width: 40, height: 40, borderRadius: 999, background: 'var(--orange)', border: 0, color: '#fff', cursor: 'pointer' }}>
                <I.Plus size={14} stroke="#fff"/>
              </button>
            </div>
          </div>
        </div>
      </div>
      <WizardCTA
        label={item.available === false ? 'Épuisé pour le moment' : `Ajouter au panier · ${qty}`}
        total={total}
        disabled={item.available === false}
        onClick={() => {
          const note = Array.from(selections.instruction || '').slice(0, LC_NOTE_MAX).join('');
          const lineItem = { ...item, qty, unitPrice: item.price, lineTotal: total, sups: [], instruction: note, composition_summary: note ? '📝 ' + Array.from(note).slice(0, 60).join('') + (Array.from(note).length > 60 ? '…' : '') : '' };
          addToCart(lineItem);
          go('cart');
        }}
      />
    </div>
  );
}

// ============================================================================
// EXPORT
// ============================================================================
Object.assign(window, {
  ScreenItemWizard,
  computeActiveSteps,
  canAdvance,
  computeTotal,
  buildLineItem,
  WIZARD_STEPS: STEP,
  WIZARD_STEP_LABELS: STEP_LABELS,
  // [MOBILE-REALIGNMENT 2026-05-16] expose bol/frites step components for e2e tests
  ScreenStepBolSupplements,
  ScreenStepBolDrink,
  // [GOAL-SYNC 2026-07-08] expose pain step + template resolver for e2e tests
  ScreenStepPain,
  wizardTemplateFor,
});
