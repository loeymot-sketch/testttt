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

const { useState: useState_w, useEffect: useEffect_w, useRef: useRef_w } = React;

// ============================================================================
// Step keys (canonical kiosk vocabulary, cf. KW.vue STEP_KEY_REGISTRY:301-325)
// ============================================================================
const STEP = {
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
function computeActiveSteps(item, selections) {
  if (!item) return [STEP.RECAP];
  const cat = window.LC.menu.findCategory(item.category_id);
  // [MOBILE-REALIGNMENT 2026-05-16] item.wizard_template priority over category
  // (kiosk parity: KW.vue:890-895 — item override allowed for special items).
  const template = item.wizard_template || (cat && cat.wizard_template) || 'simple';
  const steps = [];

  // Per-template canonical sequence, filtered by item flags
  switch (template) {
    case 'tacos':
    case 'sandwich':
      if ((item.viande_count || item.viandes) > 0) steps.push(STEP.VIANDES);
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_crudites) steps.push(STEP.CRUDITES);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      break;
    case 'burger':
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_crudites) steps.push(STEP.CRUDITES);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      break;
    case 'assiette':
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      break;
    case 'omelette':
      // Ojja + Omelettes + Menus Enfants (V3.8) — frites already in price → no menu, no frites_style
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_crudites) steps.push(STEP.CRUDITES);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      break;
    case 'salade':
      // [Owner re-cadrage 2026-05-11] Salade simplifiée : sauce(opt) + suppléments(opt) + menu(opt) + cascade.
      // Kiosk a 6 steps (garnitures+sauce+menu+frites_style+supplements+recap) — c'est une bêtise pour
      // une salade dont la composition est déjà fixée par le nom (Salade Royale = Laitue+Tomate+Maïs+Poulet+Olives).
      // Logique humaine = "choisis sauce optionnelle + suppléments optionnels + veux-tu rajouter frites/boisson ?"
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      break;
    case 'snacking':
      // Wings + tenders (cat 8/313). Sauce générique 15 sauces (U2 owner: pas BBQ/Nashville).
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
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
        break;
      }
      // Unknown custom profile — fall through to simple
      // FALLTHROUGH
    case 'simple':
    default:
      // Cas spéciaux : frites standalone, sauce sup, ou direct add
      if (item.has_frites_style) steps.push(STEP.FRITES_STYLE);
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false && (item.has_sauce || item.has_frites_style)) {
        steps.push(STEP.SUPPLEMENTS);
      }
      break;
  }

  // Cascade formule menu (inserted right after MENU step)
  const menuChoice = selections && selections.menuChoice;
  if (steps.includes(STEP.MENU) && menuChoice && menuChoice !== 'none') {
    const menuIdx = steps.indexOf(STEP.MENU);
    const cascade = [];
    if (menuChoice === 'full' || menuChoice === 'boisson') cascade.push(STEP.DRINK);
    if (menuChoice === 'full' || menuChoice === 'frites') {
      cascade.push(STEP.FRITES_STYLE);
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
    case STEP.VIANDES:
      return (selections.meatIds || []).length === (item.viande_count || item.viandes);
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
    bolSupplementIds: selections.bolSupplementIds,
    bolDrinkId: selections.bolDrinkId,
    formuleId: selections.menuChoice && selections.menuChoice !== 'none'
      ? (selections.menuChoice === 'full' ? 'f-menu' : selections.menuChoice === 'frites' ? 'f-frites' : 'f-boisson')
      : null,
    fritesStyleId: selections.fritesStyleId,
    fritesSauceIds: selections.fritesSauceIds,
    qty: selections.qty || 1,
  });
}

// ============================================================================
// Common shell — header + dots + sticky CTA (Claude Design rdw-* refactor cycle C 2026-05-11)
// Preserves all a11y (aria-live, role, tabIndex, aria-disabled, focus mgmt) +
// FSM (back/onClose handlers + stepIndex/stepTotal/title/headingRef).
// ============================================================================
function WizardHeader({ stepIndex, stepTotal, title, onBack, onClose, headingRef, scrolled = false }) {
  return (
    <div className={'rdw-header' + (scrolled ? ' is-scrolled' : '')} style={{ position: 'sticky', top: 0, zIndex: 5, paddingTop: 'calc(var(--ios-safe-top) - 28px)' }}>
      <div className="rdw-header-row">
        <button
          className="rdw-back"
          onClick={onBack}
          aria-label={stepIndex === 0 ? 'Fermer le wizard' : 'Étape précédente'}
        >
          <I.Back size={20}/>
        </button>
        <span className="rdw-stepcount" role="status" aria-live="polite">
          <b>{stepIndex + 1}</b> / {stepTotal}
        </span>
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
      <div className="rdw-progress" role="progressbar" aria-label="Progression de la personnalisation" aria-valuenow={stepIndex + 1} aria-valuemin={1} aria-valuemax={stepTotal} aria-valuetext={`Étape ${stepIndex + 1} sur ${stepTotal}`}>
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
          <span className="rdw-cta-chip" style={{ background: disabled ? 'transparent' : 'rgba(255,255,255,0.14)' }}>
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
function ChoiceCard({ on, onPick, ariaRole = 'radio', children, accent = 'orange', disabled = false }) {
  const handleKey = (e) => {
    if (disabled) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onPick();
    }
  };
  // Note: accent='green' for crudites step (Type 3 visual contract). The
  // rdw-choice.is-on uses --orange shadow by default ; accent override only
  // applies via inline overrides below (preserve cycle B contract).
  const isGreen = accent === 'green';
  return (
    <div
      role={ariaRole}
      aria-checked={on ? 'true' : 'false'}
      aria-disabled={disabled ? 'true' : undefined}
      tabIndex={disabled ? -1 : 0}
      onClick={disabled ? undefined : onPick}
      onKeyDown={handleKey}
      className={'rdw-choice' + (on ? ' is-on' : '')}
      style={{
        opacity: disabled ? 0.4 : 1,
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
// STEP 1 — Viandes
// ============================================================================
function ScreenStepViandes({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const meatIds = selections.meatIds || [];
  const required = item.viandes;
  const remaining = required - meatIds.length;
  const togglePush = (id) => {
    setSelections(s => {
      const arr = s.meatIds || [];
      if (arr.includes(id)) return { ...s, meatIds: arr.filter(x => x !== id) };
      if (arr.length >= required) return { ...s, meatIds: [...arr.slice(1), id] };
      return { ...s, meatIds: [...arr, id] };
    });
  };
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <div role="status" aria-live="polite" style={{ marginBottom: 14, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <p style={{ margin: 0, fontSize: 14, color: 'var(--gray-4)' }}>
          Choisis <strong>{required}</strong> viande{required > 1 ? 's' : ''}
        </p>
        <span style={{ fontSize: 11, fontWeight: 700, color: meatIds.length === required ? 'var(--green-text)' : 'var(--orange-text)', letterSpacing: '0.08em' }}>
          {meatIds.length}/{required}
        </span>
      </div>
      <div role="group" aria-label={`Choisis ${required} viande${required > 1 ? 's' : ''}`} style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 }}>
        {lcMenu.meats.map(m => {
          const on = meatIds.includes(m.id);
          return (
            <ChoiceCard key={m.id} on={on} onPick={() => togglePush(m.id)} ariaRole="checkbox">
              <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 8 }}>
                {/* Phase 6.A real-asset thumb (kiosk viande_*.png) */}
                {m.image
                  ? <img src={m.image} alt="" aria-hidden="true" style={{ width: 32, height: 32, objectFit: 'cover', borderRadius: 6, flexShrink: 0 }}/>
                  : <span aria-hidden="true">{m.emoji}</span>}
                <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>{m.name}</span>
              </span>
              <span aria-hidden="true" style={{ width: 18, height: 18, borderRadius: 4, border: on ? '0' : '2px solid var(--gray-2)', background: on ? 'var(--orange)' : '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                {on && <I.Check size={12} stroke="#fff" sw={3}/>}
              </span>
            </ChoiceCard>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 2 — Sauce (1 gratuite, +0.50€/sauce additionnelle)
// ============================================================================
function ScreenStepSauce({ item, selections, setSelections, headingRef, sauceField = 'sauceIds' }) {
  const lcMenu = window.LC.menu;
  const sauceIds = selections[sauceField] || [];
  const SANS_SAUCE = 's-sans';
  const toggle = (id) => {
    setSelections(s => {
      const arr = s[sauceField] || [];
      // "Sans Sauce" exclusivity (kiosk: KioskStepSauceComponent.vue:65-78)
      if (id === SANS_SAUCE) return { ...s, [sauceField]: [SANS_SAUCE] };
      const nextArr = arr.includes(id) ? arr.filter(x => x !== id) : [...arr.filter(x => x !== SANS_SAUCE), id];
      return { ...s, [sauceField]: nextArr };
    });
  };
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        1 gratuite · sup <strong>0,50 €</strong> par sauce additionnelle
      </p>
      <div role="group" aria-label="Sauces" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 6 }}>
        {lcMenu.sauces.map(s => {
          const on = sauceIds.includes(s.id);
          const idx = sauceIds.indexOf(s.id);
          const free = idx === 0;
          return (
            <ChoiceCard key={s.id} on={on} onPick={() => toggle(s.id)} ariaRole="checkbox">
              <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 6 }}>
                {/* Phase 6.A real-asset color swatch (kiosk generated sauce_*.svg) */}
                {s.image
                  ? <img src={s.image} alt="" aria-hidden="true" style={{ width: 18, height: 18, objectFit: 'cover', borderRadius: 999, flexShrink: 0, border: '1px solid var(--gray-2)' }}/>
                  : (s.is_spicy && <span aria-hidden="true">🌶️</span>)}
                <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.name}{s.is_spicy && s.image ? ' 🌶️' : ''}</span>
              </span>
              {on && !free && (
                <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--ink)', fontWeight: 700, flexShrink: 0 }}>+0,50€</span>
              )}
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
                <div style={{ fontSize: 13, fontWeight: 700, color: on ? 'var(--green-text)' : 'var(--gray-3)' }}>
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
// STEP 4 — Suppléments (optionnel)
// ============================================================================
function ScreenStepSupplements({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const supplementIds = selections.supplementIds || [];
  const toggle = (id) => {
    setSelections(s => {
      const arr = s.supplementIds || [];
      return { ...s, supplementIds: arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id] };
    });
  };
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>Optionnel · ajoute ce que tu veux</p>
      <div role="group" aria-label="Suppléments" style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
        {lcMenu.supplements.map(s => {
          const on = supplementIds.includes(s.id);
          const handleKey = (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(s.id); }
          };
          return (
            <div
              key={s.id}
              role="checkbox"
              aria-checked={on ? 'true' : 'false'}
              tabIndex={0}
              onClick={() => toggle(s.id)}
              onKeyDown={handleKey}
              className="lc-toggle-row"
              style={{ outline: 'none' }}
            >
              {/* Phase 6.A real-asset thumb (kiosk supplement_*.png) */}
              {s.image && (
                <img src={s.image} alt="" aria-hidden="true" style={{ width: 36, height: 36, objectFit: 'cover', borderRadius: 8, flexShrink: 0, marginRight: 10 }}/>
              )}
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }}>{s.name}</div>
                <div style={{ fontSize: 11, fontFamily: 'var(--font-mono)', color: 'var(--ink)', fontWeight: 700, marginTop: 2 }}>+ {s.price.toFixed(2).replace('.', ',')} €</div>
              </div>
              <div aria-hidden="true" className={`lc-checkbox ${on ? 'lc-checkbox--on' : ''}`}>
                {on && <I.Check size={12} stroke="#fff" sw={3}/>}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ============================================================================
// STEP 5 — Menu (radio: full/frites/boisson/none)
// ============================================================================
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
  const options = [
    { id: 'full',    name: 'Menu complet',  desc: 'Frites + Boisson', price: 3.00, emoji: '🍟🥤' },
    { id: 'frites',  name: 'Ajouter Frites', desc: 'Frites uniquement', price: 2.00, emoji: '🍟' },
    { id: 'boisson', name: 'Ajouter Boisson', desc: 'Boisson uniquement', price: 2.00, emoji: '🥤' },
    { id: 'none',    name: 'Sans formule',   desc: 'Plat seul',         price: 0,    emoji: '🚫' },
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
                <span aria-hidden="true" style={{ fontSize: 22 }}>{o.emoji}</span>
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
        {lcMenu.formuleDrinks.map(d => {
          const on = drinkId === d.id;
          return (
            <ChoiceCard key={d.id} on={on} onPick={() => pick(d.id)} ariaRole="radio">
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, width: '100%', padding: '4px 0' }}>
                {/* Phase 6.A real-asset thumb (kiosk drink png) */}
                {d.image
                  ? <img src={d.image} alt="" aria-hidden="true" style={{ width: 56, height: 56, objectFit: 'contain', flexShrink: 0 }}/>
                  : <span aria-hidden="true" style={{ fontSize: 28 }}>{d.emoji}</span>}
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
                  : <span aria-hidden="true" style={{ fontSize: 24 }}>{fs.emoji}</span>}
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
  const supplementsBols = lcMenu.supplementsBols || [];
  const bolSupplementIds = selections.bolSupplementIds || [];
  const toggle = (id) => {
    setSelections(s => {
      const arr = s.bolSupplementIds || [];
      return { ...s, bolSupplementIds: arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id] };
    });
  };
  return (
    <div style={{ padding: '20px 20px 120px' }}>
      <p style={{ margin: '0 0 14px', fontSize: 14, color: 'var(--gray-4)' }}>
        Optionnel · personnalise ton bol
      </p>
      <div role="group" aria-label="Suppléments bol" style={{ background: 'var(--cream)', borderRadius: 14, overflow: 'hidden' }}>
        {supplementsBols.map(s => {
          const on = bolSupplementIds.includes(s.id);
          const isGratine = s.id === 'sb-boule-gratinee';
          const handleKey = (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(s.id); }
          };
          return (
            <div
              key={s.id}
              role="checkbox"
              aria-checked={on ? 'true' : 'false'}
              tabIndex={0}
              onClick={() => toggle(s.id)}
              onKeyDown={handleKey}
              className="lc-toggle-row"
              data-testid={`bol-supp-${s.id}`}
              style={{ outline: 'none' }}
            >
              {s.image
                ? <img src={s.image} alt="" aria-hidden="true" style={{ width: 36, height: 36, marginRight: 10, borderRadius: 8, objectFit: 'cover', background: 'var(--cream, #f5f0e8)' }} onError={(e) => { e.target.style.display = 'none'; }}/>
                : <span aria-hidden="true" style={{ fontSize: 24, marginRight: 10 }}>
                    {isGratine ? '🧀' : s.id === 'sb-oignon-frais' ? '🧅' : s.id === 'sb-jambon' ? '🥓' : '🍄'}
                  </span>}
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }}>
                  {s.name}{isGratine && <span style={{ marginLeft: 6, fontSize: 10, padding: '2px 6px', background: 'var(--orange-text)', color: '#fff', borderRadius: 4, fontWeight: 700, letterSpacing: '0.05em' }}>POPULAIRE</span>}
                </div>
                <div style={{ fontSize: 11, fontFamily: 'var(--font-mono)', color: 'var(--ink)', fontWeight: 700, marginTop: 2 }}>
                  + {s.price.toFixed(2).replace('.', ',')} €
                </div>
              </div>
              <div aria-hidden="true" className={`lc-checkbox ${on ? 'lc-checkbox--on' : ''}`}>
                {on && <I.Check size={12} stroke="#fff" sw={3}/>}
              </div>
            </div>
          );
        })}
      </div>
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
            <span aria-hidden="true" style={{ fontSize: 28 }}>🚫</span>
            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--ink)', textAlign: 'center' }}>Aucune boisson</span>
          </div>
        </ChoiceCard>
        {lcMenu.formuleDrinks.map(d => {
          const on = bolDrinkId === d.id;
          const price = lcMenu.priceForDrinkAddon ? lcMenu.priceForDrinkAddon(d.id) : 1.50;
          return (
            <ChoiceCard key={d.id} on={on} onPick={() => pick(d.id)} ariaRole="radio">
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, width: '100%', padding: '4px 0' }}>
                {d.image
                  ? <img src={d.image} alt="" aria-hidden="true" style={{ width: 56, height: 56, objectFit: 'contain', flexShrink: 0 }}/>
                  : <span aria-hidden="true" style={{ fontSize: 28 }}>{d.emoji}</span>}
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
// STEP 9 — Recap (composition résumé + total + Ajouter au panier)
// ============================================================================
function ScreenStepRecap({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const meatLabels = (selections.meatIds || []).map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean);
  const sauceLabels = (selections.sauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  const cruditeIds = selections.cruditeIds || lcMenu.defaultCruditeIds();
  const cruditeAll = lcMenu.crudites.map(c => c.id);
  const cruditeRemoved = cruditeAll.filter(id => !cruditeIds.includes(id)).map(id => (lcMenu.crudites.find(c => c.id === id) || {}).name).filter(Boolean);
  const cruditeKept = lcMenu.crudites.filter(c => cruditeIds.includes(c.id)).map(c => c.name);
  const supLabels = (selections.supplementIds || []).map(id => (lcMenu.supplements.find(s => s.id === id) || {}).name).filter(Boolean);
  const bolSupLabels = (selections.bolSupplementIds || []).map(id => (lcMenu.supplementsBols.find(s => s.id === id) || {}).name).filter(Boolean);
  const bolDrinkLabel = selections.bolDrinkId
    ? (lcMenu.formuleDrinks.find(d => d.id === selections.bolDrinkId) || {}).name
    : (selections.bolDrinkId === null ? 'Aucune' : null);
  const drinkLabel = selections.drinkId ? (lcMenu.formuleDrinks.find(d => d.id === selections.drinkId) || {}).name : null;
  const styleLabel = selections.fritesStyleId !== undefined
    ? (lcMenu.fritesStyles.find(fs => fs.id === selections.fritesStyleId) || {}).name
    : null;
  const fritesSauceLabels = (selections.fritesSauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  // [MOBILE-REALIGNMENT 2026-05-16] Show locked sauce for items with sauce_locked
  // (e.g. Sandwich Cayenne, Big Cayenne, Galette Cayenne → "Sauce Cayenne maison incluse").
  const sauceLockedLabel = item.sauce_locked ? `Sauce ${item.sauce_locked} maison (incluse)` : null;
  // [MOBILE-REALIGNMENT 2026-05-16] Show fixed meat for bols (item.bol_meat_fixed)
  const bolMeatLabel = item.bol_meat_fixed || null;
  const bolBaseLabel = item.has_bol_wizard
    ? (item.slug && item.slug.indexOf('bowl-frites-') === 0 ? 'Frites' :
       item.slug && item.slug.indexOf('bowl-riz-') === 0 ? 'Riz basmati' : null)
    : null;
  const menuLabel = (() => {
    // [CAISSE-SYNC 2026-05-30] price read from canonical FORMULES (was hardcoded 2,50€; DB caisse = 3,00€)
    const _fMenu = ((window.LC && window.LC.menu && window.LC.menu.formules) || []).find(function (f) { return f.id === 'f-menu'; });
    const _fMenuPrice = (_fMenu ? _fMenu.price : 3).toFixed(2).replace('.', ',').replace(',00', '');
    if (selections.menuChoice === 'full') return 'Menu (Frites + Boisson) +' + _fMenuPrice + '€';
    if (selections.menuChoice === 'frites') return 'Ajouter Frites +2€';
    if (selections.menuChoice === 'boisson') return 'Ajouter Boisson +2€';
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
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid var(--gray-1)' }}>
      <span style={{ fontSize: 12, color: 'var(--gray-4)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>{label}</span>
      <span style={{ fontSize: 13, color: 'var(--ink)', fontWeight: 600, textAlign: 'right', maxWidth: '60%' }}>{value}</span>
    </div>
  ) : null;

  return (
    <div style={{ padding: '20px 20px 210px' }}>
      <div style={{ background: 'var(--cream)', borderRadius: 16, padding: '14px 0 0', overflow: 'hidden' }}>
        <div style={{ padding: '0 16px 12px', display: 'flex', alignItems: 'center', gap: 10 }}>
          <span aria-hidden="true" style={{ fontSize: 28 }}>{item.kiosk_emoji || '🍽️'}</span>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 16, fontFamily: 'var(--font-display)', textTransform: 'uppercase' }}>{item.name}</div>
            <div style={{ fontSize: 11, color: 'var(--gray-4)' }}>Récapitulatif de ta commande</div>
          </div>
          {/* [D4 EU FIC 1169/2011] AllergenBadge sur recap — disclosure légale obligatoire.
              [MASSIVE-LOGIC HEAL 2026-05-17] now uses aggregatedAllergens (item + supps + drinks)
              instead of item.allergens only — full FIC disclosure per selection. */}
          {window.AllergenBadge && <window.AllergenBadge allergens={aggregatedAllergens} size="lg"/>}
        </div>
        {/* [MOBILE-REALIGNMENT 2026-05-16] Bol pre-filled rows : base + viande fixed by item */}
        {bolBaseLabel && <Row label="Base" value={bolBaseLabel}/>}
        {bolMeatLabel && <Row label="Viande" value={bolMeatLabel}/>}
        {sauceLockedLabel && !has(STEP.SAUCE) && <Row label="Sauce" value={sauceLockedLabel}/>}
        {has(STEP.VIANDES) && <Row label="Viandes" value={meatLabels.length ? meatLabels.join(' · ') : '—'}/>}
        {has(STEP.SAUCE) && <Row label="Sauce" value={sauceLabels.length ? sauceLabels.join(' / ') : '—'}/>}
        {has(STEP.CRUDITES) && (
          cruditeRemoved.length > 0
            ? <Row label="Crudités retirées" value={cruditeRemoved.join(' / ')}/>
            : <Row label="Crudités" value={cruditeKept.length ? 'Toutes (' + cruditeKept.join(' · ') + ')' : 'Toutes'}/>
        )}
        {has(STEP.SUPPLEMENTS) && <Row label="Suppléments" value={supLabels.length ? supLabels.join(' + ') : 'Aucun'}/>}
        {has(STEP.BOL_SUPPLEMENTS) && <Row label="Suppléments bol" value={bolSupLabels.length ? bolSupLabels.join(' + ') : 'Aucun'}/>}
        {has(STEP.BOL_DRINK) && <Row label="Boisson bol" value={bolDrinkLabel || 'Aucune'}/>}
        {has(STEP.MENU) && <Row label="Formule" value={menuLabel || 'Sans formule'}/>}
        {has(STEP.DRINK) && <Row label="Boisson" value={drinkLabel || '—'}/>}
        {has(STEP.FRITES_STYLE) && <Row label="Style frites" value={styleLabel || '—'}/>}
        {has(STEP.FRITES_SAUCE) && <Row label="Sauce frites" value={fritesSauceLabels.length ? fritesSauceLabels.join(' / ') : '—'}/>}
      </div>

      {/* [D5 Owner re-cadrage 2026-05-11] Instructions spéciales — kiosk a champ instruction 190 char
          (KW.vue:151-161), mobile alignment. Notes pour la cuisine : sans oignon, bien cuit, etc. */}
      <div style={{ marginTop: 14 }}>
        <label htmlFor="lc-recap-instructions" style={{ display: 'block', fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'var(--gray-4)', marginBottom: 6 }}>
          Instructions spéciales <span style={{ fontWeight: 400, textTransform: 'none', letterSpacing: 0 }}>(optionnel)</span>
        </label>
        <textarea
          id="lc-recap-instructions"
          data-testid="recap-instructions"
          maxLength={190}
          rows={2}
          placeholder="Ex. Sans oignon, bien cuit, etc."
          value={selections.instruction || ''}
          onChange={(e) => setSelections(s => ({ ...s, instruction: e.target.value.slice(0, 190) }))}
          style={{ width: '100%', padding: '10px 12px', fontSize: 13, lineHeight: 1.35, border: '1.5px solid var(--gray-2)', borderRadius: 12, background: '#fff', color: 'var(--ink)', fontFamily: 'inherit', resize: 'none', boxSizing: 'border-box' }}
        />
        <div aria-live="polite" style={{ fontSize: 10, color: 'var(--gray-3)', textAlign: 'right', marginTop: 2 }}>
          {(selections.instruction || '').length} / 190
        </div>
      </div>

      <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', background: 'var(--ink)', borderRadius: 16 }}>
        <span style={{ color: '#fff', fontSize: 13, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Quantité</span>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <button onClick={() => setQty(qty - 1)} aria-label="Diminuer la quantité" style={{ width: 32, height: 32, borderRadius: 999, background: 'rgba(255,255,255,0.15)', border: 0, color: '#fff', cursor: 'pointer' }}>
            <I.Minus size={14} stroke="#fff"/>
          </button>
          <span aria-live="polite" style={{ color: 'var(--orange)', fontFamily: 'var(--font-display)', fontSize: 24, minWidth: 24, textAlign: 'center' }}>{qty}</span>
          <button onClick={() => setQty(qty + 1)} aria-label="Augmenter la quantité" style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--orange)', border: 0, color: '#fff', cursor: 'pointer' }}>
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
  const sauceLabels = (selections.sauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  const cruditeIds = selections.cruditeIds || lcMenu.defaultCruditeIds();
  const cruditeRemoved = lcMenu.crudites.filter(c => !cruditeIds.includes(c.id)).map(c => c.name);
  const supLabels = (selections.supplementIds || []).map(id => (lcMenu.supplements.find(s => s.id === id) || {}).name).filter(Boolean);
  const bolSupLabels = (selections.bolSupplementIds || []).map(id => (lcMenu.supplementsBols.find(s => s.id === id) || {}).name).filter(Boolean);
  const bolDrinkLabel = selections.bolDrinkId
    ? (lcMenu.formuleDrinks.find(d => d.id === selections.bolDrinkId) || {}).name
    : null;
  const drinkLabel = selections.drinkId ? (lcMenu.formuleDrinks.find(d => d.id === selections.drinkId) || {}).name : null;
  const styleLabel = selections.fritesStyleId !== undefined
    ? (lcMenu.fritesStyles.find(fs => fs.id === selections.fritesStyleId) || {}).name
    : null;
  const fritesSauceLabels = (selections.fritesSauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  const summary = [];
  // [MOBILE-REALIGNMENT 2026-05-16] Bol pre-filled context (base + viande fixed)
  if (item.has_bol_wizard) {
    const baseLabel = item.slug && item.slug.indexOf('bowl-frites-') === 0 ? 'Frites' :
                      item.slug && item.slug.indexOf('bowl-riz-') === 0 ? 'Riz' : null;
    if (baseLabel) summary.push(baseLabel);
    if (item.bol_meat_fixed) summary.push(item.bol_meat_fixed);
  }
  if (meatLabels.length) summary.push(meatLabels.join(' + '));
  if (sauceLabels.length) summary.push('Sauce ' + sauceLabels.join('/'));
  if (cruditeRemoved.length) summary.push('Sans ' + cruditeRemoved.map(s => s.toLowerCase()).join('/'));
  if (supLabels.length) summary.push('+ ' + supLabels.join(' + '));
  if (bolSupLabels.length) summary.push('+ ' + bolSupLabels.join(' + '));
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
    instruction: (selections.instruction || '').slice(0, 190),
    composition_summary: summary.join(' · ') + ((selections.instruction && selections.instruction.length) ? ' · 📝 ' + selections.instruction.slice(0, 60) + (selections.instruction.length > 60 ? '…' : '') : ''),
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

  const [selections, setSelections] = useState_w(() => {
    // [MOBILE-REALIGNMENT 2026-05-16] Pre-fill defaults from composer profile + item flags
    // (kiosk parity: KW.vue initSelections — sauce default + bol context).
    const init = {
      meatIds: [],
      sauceIds: [],
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
    // Bol sauce default (e.g. bowl-frites-curry → 'Curry' sauce pre-selected)
    if (item && item.bol_sauce_default) {
      const defaultSauce = lcMenu.sauces.find(s => s.name === item.bol_sauce_default);
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

  // If item not found, render error early
  if (!item) {
    return (
      <div data-screen-label="09 Item Detail" style={{ position: 'absolute', inset: 0, background: '#fff', padding: 40, paddingTop: 100, textAlign: 'center' }}>
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

  // Clamp stepIndex if cascade insertion changed steps array
  const clampedIndex = Math.min(stepIndex, activeSteps.length - 1);
  const currentKey = activeSteps[clampedIndex];
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
    setStepIndex(i => Math.max(0, i - 1));
  };
  const onNext = () => {
    if (!valid) return;
    if (isRecap) {
      addToCart(buildLineItem(item, selections));
      go('cart');
      return;
    }
    setStepIndex(i => Math.min(activeSteps.length - 1, i + 1));
  };

  // Hint message for disabled CTA
  const hint = (() => {
    if (valid) return null;
    if (currentKey === STEP.VIANDES) {
      const required = item.viande_count || item.viandes;
      const remain = required - (selections.meatIds || []).length;
      return `Encore ${remain} viande${remain > 1 ? 's' : ''} à choisir`;
    }
    if (currentKey === STEP.SAUCE) return 'Choisis au moins une sauce (ou Sans Sauce)';
    if (currentKey === STEP.MENU) return 'Choisis ta formule pour continuer';
    if (currentKey === STEP.DRINK) return 'Choisis ta boisson';
    if (currentKey === STEP.FRITES_STYLE) return 'Choisis ton style de frites';
    if (currentKey === STEP.FRITES_SAUCE) return 'Choisis au moins une sauce pour les frites';
    return null;
  })();

  // Render step body
  let body;
  switch (currentKey) {
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
    <div data-screen-label={`09 Item Wizard ${stepTitle}`} style={{ position: 'absolute', inset: 0, background: 'var(--paper)', overflow: 'hidden' }}>
      <div style={{ position: 'absolute', inset: 0, overflowY: 'auto', overflowX: 'hidden', WebkitOverflowScrolling: 'touch' }}>
        <WizardHeader
          stepIndex={clampedIndex}
          stepTotal={activeSteps.length}
          title={stepTitle}
          onBack={onPrev}
          onClose={() => go('back')}
          headingRef={headingRef}
        />
        {/* rdw-step keyed wrapper triggers slide-x entry animation on step change */}
        <div key={currentKey} className="rdw-step">
          {body}
        </div>
      </div>
      <WizardCTA
        label={isRecap ? `Ajouter au panier · ${selections.qty || 1}` : 'Suivant'}
        total={total}
        disabled={!valid}
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
  const qty = selections.qty || 1;
  const setQty = (q) => setSelections(s => ({ ...s, qty: Math.max(1, q) }));
  const total = item.price * qty;
  return (
    <div data-screen-label="09 Item Direct" style={{ position: 'absolute', inset: 0, background: 'var(--paper)' }}>
      <div className="lc-screen" style={{ paddingBottom: 130 }}>
        <div style={{ position: 'relative', height: 280, background: 'var(--ink)' }}>
          <Slot id={item.thumb} h="100%" radius={0} placeholder={item.name} src={item.image} alt={item.name}/>
          <div style={{ position: 'absolute', top: 'calc(var(--ios-safe-top) - 14px)', left: 14, right: 14, display: 'flex', justifyContent: 'space-between', zIndex: 2 }}>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Retour"><I.Back size={20}/></IconBtn>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)" ariaLabel="Fermer"><I.Close size={18}/></IconBtn>
          </div>
        </div>
        <div style={{ padding: '24px 20px' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 32, lineHeight: 0.95 }}>{item.name}</h1>
          <p style={{ marginTop: 10, fontSize: 14, lineHeight: 1.5, color: 'var(--gray-4)' }}>{item.description}</p>
          {/* [D4 EU FIC 1169/2011] AllergenBadge sur item detail */}
          {window.AllergenBadge && <div style={{ marginTop: 12 }}><window.AllergenBadge allergens={item.allergens} size="lg"/></div>}
          <div style={{ marginTop: 20, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', background: 'var(--ink)', borderRadius: 16 }}>
            <span style={{ color: '#fff', fontSize: 13, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Quantité</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <button onClick={() => setQty(qty - 1)} aria-label="Diminuer la quantité" style={{ width: 32, height: 32, borderRadius: 999, background: 'rgba(255,255,255,0.15)', border: 0, color: '#fff', cursor: 'pointer' }}>
                <I.Minus size={14} stroke="#fff"/>
              </button>
              <span aria-live="polite" style={{ color: 'var(--orange)', fontFamily: 'var(--font-display)', fontSize: 24, minWidth: 24, textAlign: 'center' }}>{qty}</span>
              <button onClick={() => setQty(qty + 1)} aria-label="Augmenter la quantité" style={{ width: 32, height: 32, borderRadius: 999, background: 'var(--orange)', border: 0, color: '#fff', cursor: 'pointer' }}>
                <I.Plus size={14} stroke="#fff"/>
              </button>
            </div>
          </div>
        </div>
      </div>
      <WizardCTA
        label={`Ajouter au panier · ${qty}`}
        total={total}
        disabled={false}
        onClick={() => {
          const lineItem = { ...item, qty, unitPrice: item.price, lineTotal: total, sups: [], composition_summary: '' };
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
});
