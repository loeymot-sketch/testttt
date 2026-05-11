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
  recap: 'Récapitulatif',
};

// ============================================================================
// Active-steps computation (mirrors KW.vue computeActiveSteps + V3.8 templates)
// ============================================================================
function computeActiveSteps(item, selections) {
  if (!item) return [STEP.RECAP];
  const cat = window.LC.menu.findCategory(item.category_id);
  const template = (cat && cat.wizard_template) || 'simple';
  const steps = [];

  // Per-template canonical sequence, filtered by item flags
  switch (template) {
    case 'tacos':
    case 'sandwich':
      if (item.viandes > 0) steps.push(STEP.VIANDES);
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
      // D1 owner-gate decision: scope simplifié = sauce + suppléments uniquement
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      break;
    case 'snacking':
      // Wings + tenders (cat 8/313). Sauce générique 15 sauces (U2 owner: pas BBQ/Nashville).
      if (item.has_sauce) steps.push(STEP.SAUCE);
      if (item.has_menu_addon) steps.push(STEP.MENU);
      if (item.has_supplements !== false) steps.push(STEP.SUPPLEMENTS);
      break;
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
      return (selections.meatIds || []).length === item.viandes;
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
    formuleId: selections.menuChoice && selections.menuChoice !== 'none'
      ? (selections.menuChoice === 'full' ? 'f-menu' : selections.menuChoice === 'frites' ? 'f-frites' : 'f-boisson')
      : null,
    fritesStyleId: selections.fritesStyleId,
    fritesSauceIds: selections.fritesSauceIds,
    qty: selections.qty || 1,
  });
}

// ============================================================================
// Common shell — header + dots + sticky CTA
// ============================================================================
function WizardHeader({ stepIndex, stepTotal, title, onBack, onClose, headingRef }) {
  return (
    <div style={{ position: 'sticky', top: 0, zIndex: 5, background: 'var(--paper)', borderBottom: '1px solid var(--gray-1)' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: 'calc(var(--ios-safe-top) - 28px) 16px 12px' }}>
        <button
          onClick={onBack}
          aria-label={stepIndex === 0 ? 'Fermer le wizard' : 'Étape précédente'}
          style={{ width: 40, height: 40, borderRadius: 999, border: 0, background: 'var(--gray-1)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
        >
          <I.Back size={20}/>
        </button>
        <div style={{ flex: 1, textAlign: 'center', display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span role="status" aria-live="polite" style={{ fontSize: 10, color: 'var(--gray-3)', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
            Étape {stepIndex + 1} / {stepTotal}
          </span>
          <h1 ref={headingRef} tabIndex={-1} className="lc-display" style={{ margin: 0, fontSize: 18, lineHeight: 1, outline: 'none' }}>
            {title}
          </h1>
        </div>
        <button
          onClick={onClose}
          aria-label="Fermer"
          style={{ width: 40, height: 40, borderRadius: 999, border: 0, background: 'var(--gray-1)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
        >
          <I.Close size={18}/>
        </button>
      </div>
      <div style={{ display: 'flex', justifyContent: 'center', paddingBottom: 10 }} aria-hidden="true">
        <Dots count={stepTotal} active={stepIndex} color="var(--orange)"/>
      </div>
    </div>
  );
}

function WizardCTA({ label, total, disabled, hint, onClick }) {
  return (
    <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, padding: '12px 16px 24px', background: 'linear-gradient(180deg, rgba(255,255,255,0) 0%, var(--paper) 30%)', zIndex: 9 }}>
      {hint && (
        <div id="wizard-cta-hint" role="status" aria-live="polite" style={{ textAlign: 'center', fontSize: 11, color: 'var(--gray-4)', fontWeight: 600, marginBottom: 8 }}>
          {hint}
        </div>
      )}
      <button
        onClick={disabled ? undefined : onClick}
        aria-disabled={disabled ? 'true' : 'false'}
        aria-describedby={disabled && hint ? 'wizard-cta-hint' : undefined}
        className="lc-btn"
        style={{
          background: disabled ? 'var(--gray-2)' : 'var(--ink)',
          color: disabled ? 'var(--gray-4)' : '#fff',
          width: '100%', height: 60,
          justifyContent: 'space-between', padding: '0 24px',
          cursor: disabled ? 'not-allowed' : 'pointer',
        }}
      >
        <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>{label}</span>
        {total !== undefined && (
          <span style={{ color: disabled ? 'var(--gray-4)' : 'var(--yellow)', fontFamily: 'var(--font-mono)' }}>
            {total.toFixed(2).replace('.', ',')} €
          </span>
        )}
      </button>
    </div>
  );
}

// ============================================================================
// Reusable interactive primitives — A11y-correct (role + tabindex + keydown)
// ============================================================================
function ChoiceCard({ on, onPick, ariaRole = 'radio', children, accent = 'orange', disabled = false }) {
  const handleKey = (e) => {
    if (disabled) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onPick();
    }
  };
  const borderOn = accent === 'green' ? 'var(--green)' : 'var(--orange)';
  const bgOn = accent === 'green' ? '#E8F8ED' : 'var(--orange-soft)';
  return (
    <div
      role={ariaRole}
      aria-checked={on ? 'true' : 'false'}
      aria-disabled={disabled ? 'true' : undefined}
      tabIndex={disabled ? -1 : 0}
      onClick={disabled ? undefined : onPick}
      onKeyDown={handleKey}
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '14px 14px',
        borderRadius: 14,
        border: on ? `2px solid ${borderOn}` : '1.5px solid var(--gray-2)',
        background: on ? bgOn : 'var(--cream)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.4 : 1,
        outline: 'none',
        transition: 'all 0.12s',
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
        <span style={{ fontSize: 11, fontWeight: 700, color: meatIds.length === required ? 'var(--green)' : 'var(--orange)', letterSpacing: '0.08em' }}>
          {meatIds.length}/{required}
        </span>
      </div>
      <div role="radiogroup" aria-label={`Choisis ${required} viandes`} style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 }}>
        {lcMenu.meats.map(m => {
          const on = meatIds.includes(m.id);
          return (
            <ChoiceCard key={m.id} on={on} onPick={() => togglePush(m.id)} ariaRole="checkbox">
              <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 6 }}>
                <span aria-hidden="true">{m.emoji}</span>{m.name}
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
              <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--ink)', display: 'flex', alignItems: 'center', gap: 4 }}>
                {s.is_spicy && <span aria-hidden="true">🌶️</span>}{s.name}
              </span>
              {on && !free && (
                <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--ink)', fontWeight: 700 }}>+0,50€</span>
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
              <div style={{ flex: 1 }}>
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
                <span aria-hidden="true" style={{ fontSize: 28 }}>{d.emoji}</span>
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
                <span aria-hidden="true" style={{ fontSize: 24 }}>{fs.emoji}</span>
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
// STEP 9 — Recap (composition résumé + total + Ajouter au panier)
// ============================================================================
function ScreenStepRecap({ item, selections, setSelections, headingRef }) {
  const lcMenu = window.LC.menu;
  const meatLabels = (selections.meatIds || []).map(id => (lcMenu.meats.find(m => m.id === id) || {}).name).filter(Boolean);
  const sauceLabels = (selections.sauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  const cruditeIds = selections.cruditeIds || lcMenu.defaultCruditeIds();
  const cruditeAll = lcMenu.crudites.map(c => c.id);
  const cruditeRemoved = cruditeAll.filter(id => !cruditeIds.includes(id)).map(id => (lcMenu.crudites.find(c => c.id === id) || {}).name).filter(Boolean);
  const supLabels = (selections.supplementIds || []).map(id => (lcMenu.supplements.find(s => s.id === id) || {}).name).filter(Boolean);
  const drinkLabel = selections.drinkId ? (lcMenu.formuleDrinks.find(d => d.id === selections.drinkId) || {}).name : null;
  const styleLabel = selections.fritesStyleId !== undefined
    ? (lcMenu.fritesStyles.find(fs => fs.id === selections.fritesStyleId) || {}).name
    : null;
  const fritesSauceLabels = (selections.fritesSauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  const menuLabel = (() => {
    if (!selections.menuChoice || selections.menuChoice === 'none') return null;
    if (selections.menuChoice === 'full') return 'Menu (Frites + Boisson) +3€';
    if (selections.menuChoice === 'frites') return 'Ajouter Frites +2€';
    if (selections.menuChoice === 'boisson') return 'Ajouter Boisson +2€';
    return null;
  })();
  const qty = selections.qty || 1;
  const setQty = (q) => setSelections(s => ({ ...s, qty: Math.max(1, q) }));

  const Row = ({ label, value }) => value ? (
    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid var(--gray-1)' }}>
      <span style={{ fontSize: 12, color: 'var(--gray-4)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>{label}</span>
      <span style={{ fontSize: 13, color: 'var(--ink)', fontWeight: 600, textAlign: 'right', maxWidth: '60%' }}>{value}</span>
    </div>
  ) : null;

  return (
    <div style={{ padding: '20px 20px 130px' }}>
      <div style={{ background: 'var(--cream)', borderRadius: 16, padding: '14px 0 0', overflow: 'hidden' }}>
        <div style={{ padding: '0 16px 12px', display: 'flex', alignItems: 'center', gap: 10 }}>
          <span aria-hidden="true" style={{ fontSize: 28 }}>{item.kiosk_emoji || '🍽️'}</span>
          <div>
            <div style={{ fontSize: 16, fontFamily: 'var(--font-display)', textTransform: 'uppercase' }}>{item.name}</div>
            <div style={{ fontSize: 11, color: 'var(--gray-4)' }}>Récapitulatif de ta commande</div>
          </div>
        </div>
        <Row label="Viandes" value={meatLabels.join(' · ')}/>
        <Row label="Sauce" value={sauceLabels.join(' / ')}/>
        <Row label="Crudités retirées" value={cruditeRemoved.length > 0 ? cruditeRemoved.join(' / ') : null}/>
        <Row label="Suppléments" value={supLabels.length ? supLabels.join(' + ') : null}/>
        <Row label="Formule" value={menuLabel}/>
        <Row label="Boisson" value={drinkLabel}/>
        <Row label="Style frites" value={styleLabel}/>
        <Row label="Sauce frites" value={fritesSauceLabels.join(' / ')}/>
      </div>

      <div style={{ marginTop: 18, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', background: 'var(--ink)', borderRadius: 16 }}>
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
  const drinkLabel = selections.drinkId ? (lcMenu.formuleDrinks.find(d => d.id === selections.drinkId) || {}).name : null;
  const styleLabel = selections.fritesStyleId !== undefined
    ? (lcMenu.fritesStyles.find(fs => fs.id === selections.fritesStyleId) || {}).name
    : null;
  const fritesSauceLabels = (selections.fritesSauceIds || []).map(id => (lcMenu.sauces.find(s => s.id === id) || {}).name).filter(Boolean);
  const summary = [];
  if (meatLabels.length) summary.push(meatLabels.join(' + '));
  if (sauceLabels.length) summary.push('Sauce ' + sauceLabels.join('/'));
  if (cruditeRemoved.length) summary.push('Sans ' + cruditeRemoved.map(s => s.toLowerCase()).join('/'));
  if (supLabels.length) summary.push('+ ' + supLabels.join(' + '));
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
    menuChoice: selections.menuChoice || 'none',
    drinkId: selections.drinkId || null,
    drinkLabel,
    fritesStyleId: selections.fritesStyleId !== undefined ? selections.fritesStyleId : null,
    styleLabel,
    fritesSauceIds: selections.fritesSauceIds || [],
    fritesSauceLabels,
    composition_summary: summary.join(' · '),
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

  const [selections, setSelections] = useState_w(() => ({
    meatIds: [],
    sauceIds: [],
    cruditeIds: lcMenu.defaultCruditeIds(),
    supplementIds: [],
    menuChoice: undefined, // undefined = not yet picked, distinct from 'none'
    drinkId: undefined,
    fritesStyleId: undefined,
    fritesSauceIds: [],
    qty: 1,
  }));

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
      const remain = item.viandes - (selections.meatIds || []).length;
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
    case STEP.VIANDES:       body = <ScreenStepViandes item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.SAUCE:         body = <ScreenStepSauce item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.CRUDITES:      body = <ScreenStepCrudites item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.SUPPLEMENTS:   body = <ScreenStepSupplements item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.MENU:          body = <ScreenStepMenu item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.DRINK:         body = <ScreenStepDrink item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.FRITES_STYLE:  body = <ScreenStepFritesStyle item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.FRITES_SAUCE:  body = <ScreenStepFritesSauce item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    case STEP.RECAP:         body = <ScreenStepRecap item={item} selections={selections} setSelections={setSelections} headingRef={headingRef}/>; break;
    default:                 body = <div style={{ padding: 40, textAlign: 'center' }}><p>Étape inconnue : {currentKey}</p></div>;
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
        {body}
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
          <Slot id={item.thumb} h="100%" radius={0} placeholder={item.name}/>
          <div style={{ position: 'absolute', top: 'calc(var(--ios-safe-top) - 14px)', left: 14, right: 14, display: 'flex', justifyContent: 'space-between', zIndex: 2 }}>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)"><I.Back size={20}/></IconBtn>
            <IconBtn onClick={() => go('back')} bg="rgba(255,255,255,0.95)"><I.Close size={18}/></IconBtn>
          </div>
        </div>
        <div style={{ padding: '24px 20px' }}>
          <h1 className="lc-display" style={{ margin: 0, fontSize: 32, lineHeight: 0.95 }}>{item.name}</h1>
          <p style={{ marginTop: 10, fontSize: 14, lineHeight: 1.5, color: 'var(--gray-4)' }}>{item.description}</p>
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
});
